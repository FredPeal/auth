<?php

namespace Baka\Auth;

use Baka\Auth\Models\UserLinkedSources;
use Baka\Auth\Models\Users;
use Exception;
use Phalcon\Http\Response;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Confirmation;
use Phalcon\Validation\Validator\Email as EmailValidator;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\StringLength;
use Baka\Http\Rest\BaseController;
use Baka\Auth\Models\Sessions;

abstract class AuthController extends BaseController
{
    protected $userLinkedSourcesModel;
    protected $userModel;

    /**
     * Setup for this controller
     *
     * @return void
     */
    public function onConstruct()
    {
        $this->userLinkedSourcesModel = new UserLinkedSources();
        $this->userModel = new Users();

        if (!isset($this->config->jwt)) {
            throw new Exception('You need to configure your app JWT');
        }
    }

    /**
     * User Login
     * @method POST
     * @url /v1/auth
     *
     * @return Response
     */
    public function login(): Response
    {
        $email = $this->request->getPost('email', 'string');
        $password = $this->request->getPost('password', 'string');
        $admin = $this->request->getPost('is_admin', 'int', 0);
        $userIp = $this->request->getClientAddress();
        $remember = $this->request->getPost('remember', 'int', 1);

        //Ok let validate user password
        $validation = new Validation();
        $validation->add('email', new PresenceOf(['message' => _('The email is required.')]));
        $validation->add('password', new PresenceOf(['message' => _('The password is required.')]));

        //validate this form for password
        $messages = $validation->validate($this->request->getPost());
        if (count($messages)) {
            foreach ($messages as $message) {
                throw new Exception($message);
            }
        }

        //login the user
        $random = new \Phalcon\Security\Random();

        $userData = Users::login($email, $password, $remember, $admin, $userIp);

        $sessionId = $random->uuid();

        //save in user logs
        $payload = [
                'sessionId' => $sessionId,
                'email' => $userData->getEmail(),
                'iat' => time(),
            ];

        $token = $this->auth->make($payload);

        //start session
        $session = new Sessions();
        $session->start($userData, $sessionId, $token, $userIp, 1);

        return $this->response([
                'token' => $token,
                'time' => date('Y-m-d H:i:s'),
                'expires' => date('Y-m-d H:i:s', time() + $this->config->jwt->payload->exp),
                'id' => $userData->getId(),
            ]);
    }

    /**
     * User Login
     * @method POST
     * @url /v1/login
     *
     * @return Response
     */
    public function logout(): Response
    {
        if ($this->userData->isLoggedIn()) {
            $this->userData->logOut();
        }

        return $this->response(['Logged Out']);
    }

    /**
     * User Signup
     *
     * @method POST
     * @url /v1/users
     *
     * @return Response
     */
    public function signup(): Response
    {
        $user = $this->userModel;
        $user->email = $this->request->getPost('email', 'email');
        $user->firstname = ltrim(trim($this->request->getPost('firstname', 'string')));
        $user->lastname = ltrim(trim($this->request->getPost('lastname', 'string')));
        $user->password = ltrim(trim($this->request->getPost('password', 'string')));
        $userIp = $this->request->getClientAddress();
        $user->displayname = ltrim(trim($this->request->getPost('displayname', 'string')));
        $user->defaultCompanyName = ltrim(trim($this->request->getPost('default_company', 'string')));

        //Ok let validate user password
        $validation = new Validation();
        $validation->add('password', new PresenceOf(['message' => _('The password is required.')]));
        $validation->add('firstname', new PresenceOf(['message' => _('The firstname is required.')]));
        $validation->add('email', new EmailValidator(['message' => _('The email is not valid.')]));

        $validation->add(
            'password',
            new StringLength([
                'min' => 8,
                'messageMinimum' => _('Password is too short. Minimum 8 characters.'),
            ])
        );

        //validate this form for password
        $messages = $validation->validate($this->request->getPost());
        if (count($messages)) {
            foreach ($messages as $message) {
                throw new Exception($message);
            }
        }

        //user registration
        try {
            $this->db->begin();

            $user->signup();

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();

            throw new Exception($e->getMessage());
        }

        //login the user
        $random = new \Phalcon\Security\Random();

        $sessionId = $random->uuid();

        //save in user logs
        $payload = [
            'sessionId' => $sessionId,
            'email' => $user->getEmail(),
            'iat' => time(),
        ];

        $token = $this->auth->make($payload);

        //start session
        $session = new Sessions();
        $session->start($user, $sessionId, $token, $userIp, 1);

        $authSession = [
            'token' => $token,
            'time' => date('Y-m-d H:i:s'),
            'expires' => date('Y-m-d H:i:s', time() + $this->config->jwt->payload->exp),
            'id' => $user->getId(),
        ];

        $user->password = null;
        $this->sendEmail($user, 'signup');

        return $this->response([
            'user' => $user,
            'session' => $authSession
        ]);
    }

    /**
     * Recover user information, by getting the email for the reset pass form
     * @method POST
     * @url /v1/recover
     *
     * @return Response
     */
    public function recover(): Response
    {
        //if the user submited the form and passes the security check then we start checking
        $email = $this->request->getPost('email', 'email');

        $validation = new Validation();
        $validation->add('email', new PresenceOf(['message' => _('The email is required.')]));
        $validation->add('email', new EmailValidator(['message' => _('The email is invalid.')]));

        $messages = $validation->validate($this->request->getPost());
        if (count($messages)) {
            foreach ($messages as $message) {
                throw new Exception($message);
            }
        }

        /**
         * check if the user email exist
         * if it does creat the user activation key to send
         * send the user email
         *
         * if it doesnt existe then send the erro msg
         */
        if ($recoverUser = Users::getByEmail($email)) {
            $recoverUser->user_activation_forgot = $recoverUser->generateActivationKey();
            $recoverUser->update();

            $message = _('Please check your email inbox to complete the password recovery.');
            $this->sendEmail($recoverUser, 'recover');
        } else {
            $message = _('There is no account registered with that email.');
        }

        return $this->response($message);
    }

    /**
     * Reset the user password
     * @method PUT
     * @url /v1/reset
     *
     * @return Response
     */
    public function reset(string $key): Response
    {
        //is the key empty or does it existe?
        if (empty($key) || !$userData = Users::findFirst(['user_activation_forgot = :key:', 'bind' => ['key' => $key]])) {
            throw new Exception(_('This Key to reset password doesn\'t exist'));
        }

        // Get the new password and the verify
        $newPassword = trim($this->request->getPost('new_password', 'string'));
        $verifyPassword = trim($this->request->getPost('verify_password', 'string'));

        //Ok let validate user password
        $validation = new Validation();
        $validation->add('new_password', new PresenceOf(['message' => _('The password is required.')]));
        $validation->add('new_password', new StringLength(['min' => 8, 'messageMinimum' => _('Password is too short. Minimum 8 characters.')]));

        $validation->add('new_password', new Confirmation([
                'message' => _('Passwords do not match.'),
                'with' => 'verify_password',
            ]));

        //validate this form for password
        $messages = $validation->validate($this->request->getPost());
        if (count($messages)) {
            foreach ($messages as $message) {
                throw new Exception($message);
            }
        }

        // Check that they are the same
        if ($newPassword == $verifyPassword) {
            // Has the password and set it
            $userData->user_activation_forgot = '';
            $userData->user_active = 1;
            $userData->password = Users::passwordHash($newPassword);

            // Update
            if ($userData->update()) {
                //log the user out of the site from all devices
                $session = new Sessions();
                $session->end($userData);

                $this->sendEmail($userData, 'reset');

                return $this->response(_('Congratulations! You\'ve successfully changed your password.'));
            } else {
                throw new Exception(current($userData->getMessages()));
            }
        } else {
            throw new Exception(_('Password are not the same'));
        }
    }

    /**
     * User activation from the email signup
     * @method PUT
     * @url /v1/activate
     *
     * @return Response
     */
    public function activate(string $key): Response
    {
        $userData = Users::findFirst(['user_activation_key = :key:', 'bind' => ['key' => $key]]);
        //is the key empty or does it existe?
        if (empty($key) || !$userData) {
            throw new Exception(_('This Key doesn\'t exist'));
        }

        // ok so the key exist, now is the user is not active?
        if (!$userData->isActive()) {
            //activate it
            $userData->user_active = '1';
            $userData->user_activation_key = ' ';
            $userData->update();

            $userData->password = null;

            return $this->response($userData);
        } elseif ($userData->isActive()) {
            //wtf? are you doing here and still with an activation key?
            $userData->user_activation_key = '';
            $userData->update();

            $userData->password = null;
            return $this->response($userData);
        } else {
            throw new Exception(_('This Key doesn\'t exist'));
        }
    }

    /**
     * Set the email config array we are going to be sending
     *
     * @param String $emailAction
     * @param Users  $user
     */
    protected function sendEmail(Users $user, string $type): void
    {
        //send email for signup for this user
       /*  $this->mail
            ->to($user->getEmail())
            ->subject('Welcome to Baka')
            ->params(['name' => 'test'])
            ->template('email.volt') //you can also use template() default template is email.volt
            ->send(); */
    }
}
