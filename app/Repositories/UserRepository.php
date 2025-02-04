<?php
namespace App\Repositories;

use App\Exceptions\NexusException;
use App\Http\Resources\ExamUserResource;
use App\Http\Resources\UserResource;
use App\Models\ExamUser;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserBanLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class UserRepository extends BaseRepository
{
    public function getList(array $params)
    {
        $query = User::query();
        if (!empty($params['id'])) {
            $query->where('id', $params['id']);
        }
        if (!empty($params['username'])) {
            $query->where('username', 'like',"%{$params['username']}%");
        }
        if (!empty($params['email'])) {
            $query->where('email', 'like',"%{$params['email']}%");
        }
        if (!empty($params['class'])) {
            $query->where('class', $params['class']);
        }
        list($sortField, $sortType) = $this->getSortFieldAndType($params);
        $query->orderBy($sortField, $sortType);
        return $query->paginate();
    }

    public function getBase($id)
    {
        $user = User::query()->findOrFail($id, ['id', 'username', 'email', 'avatar']);
        return $user;
    }

    public function getDetail($id)
    {
        $with = [
            'inviter' => function ($query) {return $query->select(User::$commonFields);},
            'valid_medals'
        ];
        $user = User::query()->with($with)->findOrFail($id, User::$commonFields);
        $userResource = new UserResource($user);
        $baseInfo = $userResource->response()->getData(true)['data'];

        $examRep = new ExamRepository();
        $examProgress = $examRep->getUserExamProgress($id, ExamUser::STATUS_NORMAL);
        if ($examProgress) {
            $examResource = new ExamUserResource($examProgress);
            $examInfo = $examResource->response()->getData(true)['data'];
        } else {
            $examInfo = null;
        }



        return [
            'base_info' => $baseInfo,
            'exam_info' => $examInfo,
        ];
    }

    /**
     * create user
     *
     * @param array $params must: username, email, password, password_confirmation. optional: id, class
     * @return User
     */
    public function store(array $params)
    {
        $password = $params['password'];
        if ($password != $params['password_confirmation']) {
            throw new \InvalidArgumentException("password confirmation != password");
        }
        $username = $params['username'];
        if (!validusername($username)) {
            throw new \InvalidArgumentException("Innvalid username: $username");
        }
        $email = htmlspecialchars(trim($params['email']));
        $email = safe_email($email);
        if (!check_email($email)) {
            throw new \InvalidArgumentException("Innvalid email: $email");
        }
        if (User::query()->where('email', $email)->exists()) {
            throw new \InvalidArgumentException("The email address: $email is already in use");
        }
        if (mb_strlen($password) < 6 || mb_strlen($password) > 40) {
            throw new \InvalidArgumentException("Innvalid password: $password, it should be more than 6 character and less than 40 character");
        }
        $class = !empty($params['class']) ? intval($params['class']) : User::CLASS_USER;
        if (!isset(User::$classes[$class])) {
            throw new \InvalidArgumentException("Invalid user class: $class");
        }
        $setting = Setting::get('main');
        $secret = mksecret();
        $passhash = md5($secret . $password . $secret);
        $data = [
            'username' => $username,
            'email' => $email,
            'secret' => $secret,
            'editsecret' => '',
            'passhash' => $passhash,
            'stylesheet' => $setting['defstylesheet'],
            'added' => now()->toDateTimeString(),
            'status' => User::STATUS_CONFIRMED,
            'class' => $class
        ];
        $user = new User($data);
        if ($params['id']) {
            if (User::query()->where('id', $params['id'])->exists()) {
                throw new \InvalidArgumentException("uid: {$params['id']} already exists.");
            }
            do_log("[CREATE_USER], specific id: " . $params['id']);
            $user->id = $params['id'];
        }
        $user->save();

        return $user;
    }

    public function resetPassword($id, $password, $passwordConfirmation)
    {
        if ($password != $passwordConfirmation) {
            throw new \InvalidArgumentException("password confirmation != password");
        }
        $user = User::query()->findOrFail($id, ['id', 'username']);
        $secret = mksecret();
        $passhash = md5($secret . $password . $secret);
        $update = [
            'secret' => $secret,
            'passhash' => $passhash,
        ];
        $user->update($update);
        return true;
    }

    public function listClass()
    {
        $out = [];
        foreach(User::$classes as $key => $value) {
            $out[(string)$key] = $value['text'];
        }
        return $out;
    }

    public function disableUser(User $operator, $uid, $reason)
    {
        $targetUser = User::query()->findOrFail($uid, ['id', 'enabled', 'username']);
        if ($targetUser->enabled == User::ENABLED_NO) {
            throw new NexusException('Already disabled!');
        }
        $banLog = [
            'uid' => $uid,
            'username' => $targetUser->username,
            'reason' => $reason,
            'operator' => $operator->id,
        ];
        $modCommentText = sprintf("Disable by %s, reason: %s.", $operator->username, $reason);
        DB::transaction(function () use ($targetUser, $banLog, $modCommentText) {
            $targetUser->updateWithModComment(['enabled' => User::ENABLED_NO], $modCommentText);
            UserBanLog::query()->insert($banLog);
        });
        do_log("user: $uid, $modCommentText");
        return true;
    }

    public function enableUser(User $operator, $uid)
    {
        $targetUser = User::query()->findOrFail($uid, ['id', 'enabled', 'username', 'class']);
        if ($targetUser->enabled == User::ENABLED_YES) {
            throw new NexusException('Already enabled!');
        }
        $update = [
            'enabled' => User::ENABLED_YES
        ];
        if ($targetUser->class == User::CLASS_PEASANT) {
            // warn users until 30 days
            $until = now()->addDays(30)->toDateTimeString();
            $update['leechwarn'] = 'yes';
            $update['leechwarnuntil'] = $until;
        } else {
            $update['leechwarn'] = 'no';
            $update['leechwarnuntil'] = null;
        }
        $modCommentText = sprintf("Enable by %s.", $operator->username);
        $targetUser->updateWithModComment($update, $modCommentText);
        do_log("user: $uid, $modCommentText, update: " . nexus_json_encode($update));
        return true;
    }

    public function getInviteInfo($id)
    {
        $user = User::query()->findOrFail($id, ['id']);
        return $user->invitee_code()->with('inviter_user')->first();
    }

    public function getModComment($id)
    {
        $user = User::query()->findOrFail($id, ['modcomment']);
        return $user->modcomment;
    }



}
