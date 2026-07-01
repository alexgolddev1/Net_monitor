<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function authenticate(Request $request): Passport
    {
        $username = (string) $request->request->get('username', '');
        $password = (string) $request->request->get('password', '');
        $request->getSession()->set('_security.last_username', $username);

        return new Passport(
            new UserBadge($username, fn (string $userIdentifier) => new InMemoryUser($userIdentifier, '', ['ROLE_ADMIN'])),
            new CustomCredentials(function (string $credentials, $user) {
                $expectedUser = $_ENV['ADMIN_USER'] ?? 'admin';
                $expectedPassword = $_ENV['ADMIN_PASSWORD'] ?? 'admin123';
                if (!hash_equals($expectedUser, $user->getUserIdentifier()) || !hash_equals($expectedPassword, $credentials)) {
                    throw new CustomUserMessageAuthenticationException('Invalid credentials.');
                }
                return true;
            }, $password),
            [new CsrfTokenBadge('authenticate', (string) $request->request->get('_csrf_token'))]
        );
    }

    public function onAuthenticationSuccess(Request $request, $token, string $firewallName): ?Response
    {
        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('dashboard'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate('app_login');
    }
}
