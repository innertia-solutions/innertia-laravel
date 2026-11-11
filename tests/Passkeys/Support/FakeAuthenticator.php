<?php

namespace Innertia\Tests\Passkeys\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use ParagonIE\ConstantTime\Base64UrlSafe;

final class FakeAuthenticator
{
    public readonly string $credentialId;
    private \OpenSSLAsymmetricKey $key;
    public int $counter = 0;
    public ?string $userHandle = null;

    public function __construct(
        public string $rpId = 'localhost',
        public string $origin = 'http://localhost',
        public string $aaguid = "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0",
    ) {
        $this->key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->credentialId = random_bytes(16);
    }

    public function id(): string
    {
        return Base64UrlSafe::encodeUnpadded($this->credentialId);
    }

    /** Respuesta a navigator.credentials.create() para las opciones dadas (array JSON). */
    public function register(array $options, ?string $origin = null): array
    {
        $this->userHandle = Base64UrlSafe::decodeNoPadding($options['user']['id']);
        $d = openssl_pkey_get_details($this->key)['ec'];

        $cose = MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($d['x']))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($d['y']));

        // flags: UP (0x01) | UV (0x04) | AT (0x40)
        $authData = hash('sha256', $this->rpId, true) . chr(0x45) . pack('N', $this->counter)
            . $this->aaguid . pack('n', strlen($this->credentialId)) . $this->credentialId . (string) $cose;

        $attestation = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        $clientData = json_encode([
            'type'      => 'webauthn.create',
            'challenge' => $options['challenge'],
            'origin'    => $origin ?? $this->origin,
        ]);

        return [
            'id'       => $this->id(),
            'rawId'    => $this->id(),
            'type'     => 'public-key',
            'response' => [
                'clientDataJSON'    => Base64UrlSafe::encodeUnpadded($clientData),
                'attestationObject' => Base64UrlSafe::encodeUnpadded((string) $attestation),
                'transports'        => ['internal', 'hybrid'],
            ],
            'clientExtensionResults' => new \stdClass(),
        ];
    }

    /** Respuesta a navigator.credentials.get(). $uv=false simula "sin huella". */
    public function assert(array $options, ?string $origin = null, bool $uv = true, ?int $counter = null): array
    {
        $this->counter = $counter ?? $this->counter;
        $flags = $uv ? 0x05 : 0x01;
        $authData = hash('sha256', $this->rpId, true) . chr($flags) . pack('N', $this->counter);
        $clientData = json_encode([
            'type'      => 'webauthn.get',
            'challenge' => $options['challenge'],
            'origin'    => $origin ?? $this->origin,
        ]);

        openssl_sign($authData . hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id'       => $this->id(),
            'rawId'    => $this->id(),
            'type'     => 'public-key',
            'response' => [
                'clientDataJSON'    => Base64UrlSafe::encodeUnpadded($clientData),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
                'signature'         => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle'        => $this->userHandle !== null ? Base64UrlSafe::encodeUnpadded($this->userHandle) : null,
            ],
            'clientExtensionResults' => new \stdClass(),
        ];
    }
}
