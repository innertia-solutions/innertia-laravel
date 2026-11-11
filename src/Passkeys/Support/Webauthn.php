<?php

namespace Innertia\Passkeys\Support;

use Innertia\Passkeys\Exceptions\PasskeyVerificationFailed;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

final class Webauthn
{
    private static ?SerializerInterface $serializer = null;

    public static function serializer(): SerializerInterface
    {
        return self::$serializer ??= (new WebauthnSerializerFactory(self::attestationManager()))->create();
    }

    /** Opciones de creación (JSON listo para el navegador). */
    public static function creationOptions(string $userHandle, string $email, string $displayName, array $excludeCredentialIds): array
    {
        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create(PasskeyConfig::rpName(), PasskeyConfig::rpId()),
            PublicKeyCredentialUserEntity::create($email, $userHandle, $displayName),
            random_bytes(32),
            [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)],
            AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            array_map(
                fn (string $id) => PublicKeyCredentialDescriptor::create('public-key', $id),
                $excludeCredentialIds
            ),
            PasskeyConfig::challengeTtl() * 1000,
        );

        return self::toArray($options);
    }

    /** Opciones de assertion sin usuario (credenciales descubribles). */
    public static function requestOptions(): array
    {
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            PasskeyConfig::rpId(),
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            PasskeyConfig::challengeTtl() * 1000,
        );

        return self::toArray($options);
    }

    /** Valida un registro. Devuelve el CredentialRecord serializado + metadatos. */
    public static function verifyRegistration(array $options, array $response): array
    {
        try {
            $credential = self::serializer()->deserialize(json_encode($response), PublicKeyCredential::class, 'json');
            if (! $credential->response instanceof AuthenticatorAttestationResponse) {
                throw new PasskeyVerificationFailed('la respuesta no es de registro');
            }

            $creation = self::serializer()->deserialize(json_encode($options), PublicKeyCredentialCreationOptions::class, 'json');
            $record = AuthenticatorAttestationResponseValidator::create(self::factory()->creationCeremony())
                ->check($credential->response, $creation, PasskeyConfig::rpId());
        } catch (PasskeyVerificationFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PasskeyVerificationFailed($e->getMessage(), previous: $e);
        }

        return [
            'credential_id' => self::b64($record->publicKeyCredentialId),
            'aaguid'        => $record->aaguid->toRfc4122(),
            'credential'    => self::serializer()->serialize($record, 'json'),
        ];
    }

    /** credential_id (base64url) que trae una respuesta de assertion, sin validarla. */
    public static function credentialIdOf(array $response): string
    {
        $id = (string) ($response['rawId'] ?? $response['id'] ?? '');
        if ($id === '') {
            throw new PasskeyVerificationFailed('respuesta sin id');
        }

        return $id;
    }

    /** Valida una assertion contra la credencial guardada. Devuelve la credencial actualizada (contador). */
    public static function verifyAssertion(string $storedCredential, array $options, array $response): string
    {
        try {
            $credential = self::serializer()->deserialize(json_encode($response), PublicKeyCredential::class, 'json');
            if (! $credential->response instanceof AuthenticatorAssertionResponse) {
                throw new PasskeyVerificationFailed('la respuesta no es de ingreso');
            }

            $record  = self::serializer()->deserialize($storedCredential, CredentialRecord::class, 'json');
            $request = self::serializer()->deserialize(json_encode($options), PublicKeyCredentialRequestOptions::class, 'json');

            $updated = AuthenticatorAssertionResponseValidator::create(self::factory()->requestCeremony())
                ->check($record, $credential->response, $request, PasskeyConfig::rpId(), $credential->response->userHandle);
        } catch (PasskeyVerificationFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PasskeyVerificationFailed($e->getMessage(), previous: $e);
        }

        return self::serializer()->serialize($updated, 'json');
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function factory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins(PasskeyConfig::origins());
        $factory->setAttestationStatementSupportManager(self::attestationManager());

        return $factory;
    }

    private static function attestationManager(): AttestationStatementSupportManager
    {
        return AttestationStatementSupportManager::create([NoneAttestationStatementSupport::create()]);
    }

    private static function toArray(object $options): array
    {
        return json_decode(self::serializer()->serialize($options, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
        ]), true);
    }
}
