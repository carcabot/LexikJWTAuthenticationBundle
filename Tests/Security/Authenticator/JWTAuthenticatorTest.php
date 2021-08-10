<?php
declare(strict_types=1);

namespace Lexik\Bundle\JWTAuthenticationBundle\Tests\Security\Authenticator;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\ExpiredTokenException;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidPayloadException;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\MissingTokenException;
use Lexik\Bundle\JWTAuthenticationBundle\Response\JWTAuthenticationFailureResponse;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\JWTAuthenticator;
use Lexik\Bundle\JWTAuthenticationBundle\Security\User\PayloadAwareUserProviderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Tests\Stubs\User as AdvancedUserStub;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** @requires PHP >= 7.2 */
class JWTAuthenticatorTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        if (!class_exists(UserNotFoundException::class)) {
            $this->markTestSkipped('This test suite only concerns the new Symfony 5.3 authentication system.');
        }
    }

    public function testAuthenticate() {
        $userIdClaim = 'sub';
        $payload = [$userIdClaim => 'lexik'];
        $rawToken = 'token';
        $userRoles = ['ROLE_USER'];

        $userStub = new AdvancedUserStub('lexik', 'password', 'user@gmail.com', $userRoles);

        $jwtManager = $this->getJWTManagerMock(null, $userIdClaim);
        $jwtManager
            ->method('parse')
            ->willReturn(['sub' => 'lexik']);

        $userProvider = $this->getUserProviderMock();
        $userProvider
            ->method('loadUserByIdentifierAndPayload')
            ->with($payload['sub'], $payload)
            ->willReturn($userStub);

        $authenticator = new JWTAuthenticator(
            $jwtManager,
            $this->getEventDispatcherMock(),
            $this->getTokenExtractorMock($rawToken),
            $userProvider
        );

        $this->assertSame($userStub, ($authenticator->authenticate($this->getRequestMock()))->getUser());
    }

    public function testAuthenticateWithExpiredTokenThrowsException() {
        $jwtManager = $this->getJWTManagerMock();
        $jwtManager->method('parse')
            ->will($this->throwException(new JWTDecodeFailureException(JWTDecodeFailureException::EXPIRED_TOKEN, 'Expired JWT Token')));

        $this->expectException(ExpiredTokenException::class);

        $authenticator = new JWTAuthenticator(
            $jwtManager,
            $this->getEventDispatcherMock(),
            $this->getTokenExtractorMock('token'),
            $this->getUserProviderMock()
        );

        $authenticator->authenticate($this->getRequestMock());
    }

    public function testAuthenticateWithInvalidTokenThrowsException() {
        $jwtManager = $this->getJWTManagerMock();
        $jwtManager->method('parse')
            ->willThrowException(new JWTDecodeFailureException(
                JWTDecodeFailureException::INVALID_TOKEN,
                'Invalid JWT Token')
            );
        $authenticator = new JWTAuthenticator(
            $jwtManager,
            $this->getEventDispatcherMock(),
            $this->getTokenExtractorMock('token'),
            $this->getUserProviderMock()
        );

        $this->expectException(InvalidTokenException::class);

        $authenticator->authenticate($this->getRequestMock());
    }

    public function testAuthenticateWithUndecodableTokenThrowsException() {
        $jwtManager = $this->getJWTManagerMock();
        $jwtManager->method('parse')
            ->willThrowException(new JWTDecodeFailureException(
                JWTDecodeFailureException::INVALID_TOKEN,
                'The token was marked as invalid by an event listener after successful decoding.'
            ));

        $authenticator = new JWTAuthenticator(
            $jwtManager,
            $this->getEventDispatcherMock(),
            $this->getTokenExtractorMock('token'),
            $this->getUserProviderMock()
        );

        $this->expectException(InvalidTokenException::class);

        $authenticator->authenticate($this->getRequestMock());
    }

    public function testAuthenticationWithInvalidPayloadThrowsException() {
        $jwtManager = $this->getJWTManagerMock();
        $jwtManager->method('parse')
            ->willReturn(['foo' => 'bar']);
        $jwtManager->method('getUserIdClaim')
            ->willReturn('identifier');
        $authenticator = new JWTAuthenticator(
            $jwtManager,
            $this->getEventDispatcherMock(),
            $this->getTokenExtractorMock('token'),
            $this->getUserProviderMock()
        );

        $this->expectException(InvalidPayloadException::class);

        $authenticator->authenticate($this->getRequestMock());
    }

    public function testAuthenticateWithInvalidUserThrowsException() {
        $jwtManager = $this->getJWTManagerMock();
        $jwtManager->method('parse')
            ->willReturn(['identifier' => 'bar']);
        $jwtManager->method('getUserIdClaim')
            ->willReturn('identifier');

        $userProvider = $this->getUserProviderMock();
        $userProvider->method('loadUserByIdentifierAndPayload')
            ->willThrowException(new UserNotFoundException());

        $authenticator = new JWTAuthenticator(
            $jwtManager,
            $this->getEventDispatcherMock(),
            $this->getTokenExtractorMock('token'),
            $userProvider
        );

        $this->expectException(UserNotFoundException::class);

        $authenticator->authenticate($this->getRequestMock())->getUser();
    }

    public function testOnAuthenticationFailureWithInvalidToken() {
        $authException = new InvalidTokenException();
        $expectedResponse = new JWTAuthenticationFailureResponse('Invalid JWT Token');

        $dispatcher = $this->getEventDispatcherMock();
        $this->expectEvent(Events::JWT_INVALID, new JWTInvalidEvent($authException, $expectedResponse), $dispatcher);

        $authenticator = new JWTAuthenticator(
            $this->getJWTManagerMock(),
            $dispatcher,
            $this->getTokenExtractorMock(),
            $this->getUserProviderMock()
        );

        $response = $authenticator->onAuthenticationFailure($this->getRequestMock(), $authException);

        $this->assertEquals($expectedResponse, $response);
        $this->assertSame($expectedResponse->getMessage(), $response->getMessage());
    }

    public function testStart()
    {
        $authException = new MissingTokenException('JWT Token not found');
        $failureResponse = new JWTAuthenticationFailureResponse($authException->getMessageKey());

        $dispatcher = $this->getEventDispatcherMock();
        $this->expectEvent(Events::JWT_NOT_FOUND, new JWTNotFoundEvent($authException, $failureResponse), $dispatcher);

        $authenticator = new JWTAuthenticator(
            $this->getJWTManagerMock(),
            $dispatcher,
            $this->getTokenExtractorMock(),
            $this->getUserProviderMock()
        );

        $response = $authenticator->start($this->getRequestMock());

        $this->assertEquals($failureResponse, $response);
        $this->assertSame($failureResponse->getMessage(), $response->getMessage());
    }

    public function testCreateAuthenticatedToken()
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getRoles')->willReturn(['ROLE_USER']);

        $dispatcher = $this->getEventDispatcherMock();
        $dispatcher->expects($this->once())->method('dispatch')->with($this->equalTo(new JWTAuthenticatedEvent(['claim' => 'val'], new PostAuthenticationToken($user, 'dummy', ['ROLE_USER']))), Events::JWT_AUTHENTICATED);

        $authenticator = new JWTAuthenticator(
            $this->getJWTManagerMock(),
            $dispatcher,
            $this->getTokenExtractorMock(),
            $this->getUserProviderMock()
        );

        $passport = $this->createMock(SelfValidatingPassport::class);
        $passport->method('getUser')->willReturn($user);
        $passport->method('getAttribute')->with('payload')->willReturn(['claim' => 'val']);

        if (method_exists(FormLoginAuthenticator::class, 'createToken')) {
            $authenticator->createToken($passport, 'dummy');
        } else {
            $authenticator->createAuthenticatedToken($passport, 'dummy');
        }
    }

    private function getJWTManagerMock($userIdentityField = null, $userIdClaim = null)
    {
        $jwtManager = $this->getMockBuilder(DummyJWTManager::class)
            ->disableOriginalConstructor()
            ->getMock();

        if (null !== $userIdentityField) {
            $jwtManager
                ->expects($this->once())
                ->method('getUserIdentityField')
                ->willReturn($userIdentityField);
        }
        if (null !== $userIdClaim) {
            $jwtManager
                ->expects($this->once())
                ->method('getUserIdClaim')
                ->willReturn($userIdClaim);
        }

        return $jwtManager;
    }

    private function getEventDispatcherMock()
    {
        return $this->getMockBuilder(EventDispatcherInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function getTokenExtractorMock($returnValue = null)
    {
        $extractor = $this->getMockBuilder(TokenExtractorInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        if (null !== $returnValue) {
            $extractor
                ->expects($this->once())
                ->method('extract')
                ->willReturn($returnValue);
        }

        return $extractor;
    }

    private function getRequestMock()
    {
        return $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function getUserProviderMock()
    {
        return $this->getMockBuilder(DummyUserProvider::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function expectEvent($eventName, $event, $dispatcher)
    {
        $dispatcher->expects($this->once())->method('dispatch')->with($event, $eventName);
    }
}

abstract class DummyUserProvider implements UserProviderInterface, PayloadAwareUserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
    }

    public function loadUserByIdentifierAndPayload(string $identifier): UserInterface
    {
    }
}

abstract class DummyJWTManager implements JWTTokenManagerInterface
{
    public function parse(string $token): array
    {
    }
}
