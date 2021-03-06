<?php

/**
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; under version 2
 * of the License (non-upgradable).
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * Copyright (c) 2020 (original work) Open Assessment Technologies SA;
 */

declare(strict_types=1);

namespace OAT\Library\Lti1p3Core\Tests\Integration\Service\Server\Validator;

use Carbon\Carbon;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use OAT\Library\Lti1p3Core\Registration\RegistrationInterface;
use OAT\Library\Lti1p3Core\Service\Server\Validator\AccessTokenRequestValidationResult;
use OAT\Library\Lti1p3Core\Service\Server\Validator\AccessTokenRequestValidator;
use OAT\Library\Lti1p3Core\Tests\Resource\Logger\TestLogger;
use OAT\Library\Lti1p3Core\Tests\Traits\DomainTestingTrait;
use OAT\Library\Lti1p3Core\Tests\Traits\NetworkTestingTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

class AccessTokenRequestValidatorTest extends TestCase
{
    use DomainTestingTrait;
    use NetworkTestingTrait;

    /** @var TestLogger */
    private $logger;

    /** @var AccessTokenRequestValidator */
    private $subject;

    protected function setUp(): void
    {
        $this->logger = new TestLogger();

        $registrations = [
            $this->createTestRegistration(),
            $this->createTestRegistrationWithoutPlatformKeyChain(
                'missingKeyIdentifier',
                'missingKeyClientId'
            ),
            $this->createTestRegistration(
                'invalidKeyIdentifier',
                'invalidKeyClientId',
                $this->createTestPlatform(),
                $this->createTestTool(),
                ['deploymentIdentifier'],
                $this->createTestKeyChain(
                    'keyChainIdentifier',
                    'keySetName',
                    getenv('TEST_KEYS_ROOT_DIR') . '/RSA/public2.key'
                )
            )
        ];

        $this->subject = new AccessTokenRequestValidator(
            $this->createTestRegistrationRepository($registrations),
            $this->logger
        );
    }

    public function testValidation(): void
    {
        $registration = $this->createTestRegistration();

        $request = $this->createServerRequest(
            'GET',
            '/example',
            [],
            ['Authorization' => 'Bearer ' . $this->generateCredentials($registration)]
        );

        $result = $this->subject->validate($request, ['allowed-scope']);

        $this->assertInstanceOf(AccessTokenRequestValidationResult::class, $result);
        $this->assertFalse($result->hasError());
        $this->assertNull($result->getError());
        $this->assertEquals(
            [
                'JWT access token is not expired',
                'Registration found for client_id: registrationClientId',
                'Platform key chain found for registration: registrationIdentifier',
                'JWT access token signature is valid',
                'JWT access token scopes are valid',
            ],
            $result->getSuccesses()
        );
        $this->assertEquals($registration->getClientId(), $result->getToken()->getClaim('aud'));
    }

    public function testValidationFailureWithMissingAuthorizationHeader(): void
    {
        $result = $this->subject->validate($this->createServerRequest('GET', '/example', []));

        $this->assertInstanceOf(AccessTokenRequestValidationResult::class, $result);
        $this->assertTrue($result->hasError());
        $this->assertEquals('Missing Authorization header', $result->getError());

        $this->assertTrue(
            $this->logger->hasLog(
                LogLevel::ERROR,
                'Access token validation error: Missing Authorization header'
            )
        );
    }

    public function testValidationFailureWithExpiredToken(): void
    {
        $registration = $this->createTestRegistration();

        $now = Carbon::now();
        Carbon::setTestNow($now->subHour());

        $request = $this->createServerRequest(
            'GET',
            '/example',
            [],
            ['Authorization' => 'Bearer ' . $this->generateCredentials($registration)]
        );

        Carbon::setTestNow();

        $result = $this->subject->validate($request);

        $this->assertInstanceOf(AccessTokenRequestValidationResult::class, $result);
        $this->assertTrue($result->hasError());
        $this->assertEquals('JWT access token is expired', $result->getError());

        $this->assertTrue(
            $this->logger->hasLog(LogLevel::ERROR, 'Access token validation error: JWT access token is expired')
        );
    }

    public function testValidationFailureWithInvalidAudience(): void
    {
        $registration = $this->createTestRegistration();

        $request = $this->createServerRequest(
            'GET',
            '/example',
            [],
            ['Authorization' => 'Bearer ' . $this->generateCredentials($registration, 'invalid')]
        );

        $result = $this->subject->validate($request);

        $this->assertInstanceOf(AccessTokenRequestValidationResult::class, $result);
        $this->assertTrue($result->hasError());
        $this->assertEquals('No registration found for client_id: invalid', $result->getError());

        $this->assertTrue(
            $this->logger->hasLog(
                LogLevel::ERROR,
                'Access token validation error: No registration found for client_id: invalid'
            )
        );
    }

    public function testValidationFailureWithInvalidRegistration(): void
    {
        $registration = $this->createTestRegistration();

        $request = $this->createServerRequest(
            'GET',
            '/example',
            [],
            ['Authorization' => 'Bearer ' . $this->generateCredentials($registration, 'missingKeyClientId')]
        );

        $result = $this->subject->validate($request);

        $this->assertInstanceOf(AccessTokenRequestValidationResult::class, $result);
        $this->assertTrue($result->hasError());
        $this->assertEquals('Missing platform key chain for registration: missingKeyIdentifier', $result->getError());

        $this->assertTrue(
            $this->logger->hasLog(
                LogLevel::ERROR,
                'Access token validation error: Missing platform key chain for registration: missingKeyIdentifier'
            )
        );
    }

    public function testValidationFailureWithInvalidSignature(): void
    {
        $registration = $this->createTestRegistration();

        $request = $this->createServerRequest(
            'GET',
            '/example',
            [],
            ['Authorization' => 'Bearer ' . $this->generateCredentials($registration, 'invalidKeyClientId')]
        );

        $result = $this->subject->validate($request);

        $this->assertInstanceOf(AccessTokenRequestValidationResult::class, $result);
        $this->assertTrue($result->hasError());
        $this->assertEquals('JWT access token signature is invalid', $result->getError());

        $this->assertTrue(
            $this->logger->hasLog(
                LogLevel::ERROR,
                'Access token validation error: JWT access token signature is invalid'
            )
        );
    }

    public function testValidationFailureWithInvalidScopes(): void
    {
        $registration = $this->createTestRegistration();

        $request = $this->createServerRequest(
            'GET',
            '/example',
            [],
            ['Authorization' => 'Bearer ' . $this->generateCredentials($registration, null, ['invalid-scope'])]
        );

        $result = $this->subject->validate($request, ['allowed-scope']);

        $this->assertInstanceOf(AccessTokenRequestValidationResult::class, $result);
        $this->assertTrue($result->hasError());
        $this->assertEquals('JWT access token scopes are invalid', $result->getError());

        $this->assertTrue(
            $this->logger->hasLog(
                LogLevel::ERROR,
                'Access token validation error: JWT access token scopes are invalid'
            )
        );
    }

    private function generateCredentials(
        RegistrationInterface $registration,
        string $audience = null,
        array $scopes = ['allowed-scope']
    ): string {
        $now = Carbon::now();

        return (new Builder())
            ->permittedFor($audience ?? $registration->getClientId())
            ->identifiedBy(uniqid())
            ->issuedAt($now->getTimestamp())
            ->expiresAt($now->addSeconds(3600)->getTimestamp())
            ->withClaim('scopes', $scopes)
            ->getToken(new Sha256(), $registration->getPlatformKeyChain()->getPrivateKey())
            ->__toString();
    }
}
