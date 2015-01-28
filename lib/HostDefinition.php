<?php

namespace Aerys;

class HostDefinition {
    private $address;
    private $port;
    private $name;
    private $application;
    private $tlsContextArr = [];
    private $tlsDefaults = [
        'local_cert'            => null,
        'passphrase'            => null,
        'allow_self_signed'     => false,
        'verify_peer'           => false,
        'ciphers'               => null,
        'cafile'                => null,
        'capath'                => null,
        'single_ecdh_use'       => false,
        'ecdh_curve'            => 'prime256v1',
        'honor_cipher_order'    => true,
        'disable_compression'   => true,
        'reneg_limit'           => 0,
        'reneg_limit_callback'  => null,
        'crypto_method'         => STREAM_CRYPTO_METHOD_TLS_SERVER,
    ];

    private static $cryptoMethodMap = [
        'tls'       => STREAM_CRYPTO_METHOD_TLS_SERVER,
        'tls1'      => STREAM_CRYPTO_METHOD_TLSv1_0_SERVER,
        'tlsv1'     => STREAM_CRYPTO_METHOD_TLSv1_0_SERVER,
        'tlsv1.0'   => STREAM_CRYPTO_METHOD_TLSv1_0_SERVER,
        'tls1.1'    => STREAM_CRYPTO_METHOD_TLSv1_1_SERVER,
        'tlsv1.1'   => STREAM_CRYPTO_METHOD_TLSv1_1_SERVER,
        'tls1.2'    => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER,
        'tlsv1.2'   => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER,
        'ssl2'      => STREAM_CRYPTO_METHOD_SSLv2_SERVER,
        'sslv2'     => STREAM_CRYPTO_METHOD_SSLv2_SERVER,
        'ssl3'      => STREAM_CRYPTO_METHOD_SSLv3_SERVER,
        'sslv3'     => STREAM_CRYPTO_METHOD_SSLv3_SERVER,
        'sslv23'    => STREAM_CRYPTO_METHOD_SSLv23_SERVER,
        'any'       => STREAM_CRYPTO_METHOD_ANY_SERVER,
    ];

    public function __construct($address, $port, $name, callable $application) {
        $this->setAddress($address);
        $this->setPort($port);
        $this->name = strtolower($name);
        $this->id = ($this->name ? $this->name : $this->address) . ':' . $this->port;
        $this->application = $application;
    }

    private function setAddress($address) {
        $address = trim($address, "[]");
        if ($address === '*') {
            $this->address = $address;
        } elseif ($address === '::') {
            $this->address = '[::]';
        } elseif (!$packedAddress = @inet_pton($address)) {
            throw new \InvalidArgumentException(
                "IPv4, IPv6 or wildcard address required: {$address}"
            );
        } else {
            $this->address = isset($packedAddress[4]) ? $address : "[{$address}]";
        }
    }

    private function setPort($port) {
        if ($port != (string)(int) $port || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(
                "Invalid host port: {$port}; integer in the range [1-65535] required"
            );
        }

        $this->port = (int) $port;
    }

    /**
     * Retrieve the ID for this host
     *
     * @return string
     */
    public function getId() {
        return $this->id;
    }

    /**
     * Retrieve the IP on which the host listens (may be a wildcard "*" or "[::]")
     *
     * @return string
     */
    public function getAddress() {
        return $this->address;
    }

    /**
     * Retrieve the port on which this host listens
     *
     * @return int
     */
    public function getPort() {
        return $this->port;
    }

    /**
     * Retrieve the URI on which this host should be bound
     *
     * @return string
     */
    public function getBindableAddress() {
        $ip = ($this->address === '*') ? '0.0.0.0' : $this->address;

        return sprintf('tcp://%s:%d', $ip, $this->port);
    }

    /**
     * Retrieve the host's name
     *
     * @return string
     */
    public function getName() {
        return $this->name;
    }

    /**
     * Retrieve the callable application for this host
     *
     * @return mixed
     */
    public function getApplication() {
        return $this->application;
    }

    /**
     * Is this host's address defined by a wildcard character?
     *
     * @return bool
     */
    public function hasWildcardAddress() {
        return ($this->address === '*' || $this->address === '[::]');
    }

    /**
     * Does the specified IP address (or wildcard) match this host's address?
     *
     * @param string $address
     * @return bool
     */
    public function matchesAddress($address) {
        if ($this->address === '*' || $this->address === '[::]') {
            return true;
        }
        if ($address === '*' || $address === '[::]') {
            return true;
        }

        return (@inet_pton($this->address) === @inet_pton($address));
    }

    /**
     * Does this host have a name?
     *
     * @return bool
     */
    public function hasName() {
        return $this->name != '';
    }

    /**
     * Has this host been assigned a TLS encryption context?
     *
     * @return bool Returns true if a TLS context is assigned, false otherwise
     */
    public function isEncrypted() {
        return (bool) $this->tlsContextArr;
    }

    /**
     * Define TLS encryption settings for this host
     *
     * @param array An array mapping TLS stream context values
     * @link http://php.net/manual/en/context.ssl.php
     * @return void
     */
    public function setCrypto(array $tls) {
        if (!extension_loaded('openssl')) {
            throw new \LogicException(
                "Cannot assign crypto settings in host `{$this}`; ext/openssl required"
            );
        }

        $certPath = $tls['local_cert'];
        $certBase = basename($certPath);
        if (!$rawCert = @file_get_contents($certPath)) {
            throw new \RuntimeException(
                "TLS certificate path `{$certPath}` could not be read in host `{$this}`"
            );
        }

        if (!$cert = @openssl_x509_read($rawCert)) {
            throw new \RuntimeException(
                "`{$certBase}` does not appear to be a valid X.509 certificate in host `{$this}`"
            );
        }

        if (!preg_match("#-----BEGIN [A-Z]+ PRIVATE KEY-----#", $rawCert)) {
            throw new \RuntimeException(
                "TLS certificate `{$certBase}` appears to be missing the private key in host " .
                "`{$this}`; encrypted hosts must concatenate their private key into the same " .
                "file with the public key and any intermediate CA certs."
            );
        }

        if (!$cert = openssl_x509_parse($cert)) {
            throw new \RuntimeException(
                "Failed parsing X.509 certificate `{$certBase}` in host `{$this}`"
            );
        }

        $names = $this->parseNamesFromTlsCertArray($cert);
        if (!in_array($this->name, $names)) {
            trigger_error(
                E_USER_WARNING,
                "TLS certificate `{$certBase}` has no CN or SAN name match for host `{$this}`; " .
                "web browsers will not trust the validity of your certificate :("
            );
        }

        if (time() > $cert['validTo_time_t']) {
            date_default_timezone_set(@date_default_timezone_get() ?: 'UTC');
            $expiration = date('Y-m-d', $cert['validTo_time_t']);
            trigger_error(
                E_USER_WARNING,
                "TLS certificate `{$certBase}` for host `{$this}` expired {$expiration}; web " .
                "browsers will not trust the validity of your certificate :("
            );
        }

        if (isset($tls['crypto_method'])) {
            $tls = $this->normalizeTlsCryptoMethod($tls);
        }

        $tls = array_merge($this->tlsDefaults, $tls);
        $tls = array_filter($tls, function($value) { return isset($value); });

        $this->tlsContextArr = $tls;
    }

    private function parseNamesFromTlsCertArray(array $cert) {
        $names = [];
        if (!empty($cert['subject']['CN'])) {
            $names[] = $cert['subject']['CN'];
        }

        if (empty($cert["extensions"]["subjectAltName"])) {
            return $names;
        }

        $parts = array_map('trim', explode(',', $cert["extensions"]["subjectAltName"]));
        foreach ($parts as $part) {
            if (stripos($part, 'DNS:') === 0) {
                $names[] = substr($part, 4);
            }
        }

        return array_map('strtolower', $names);
    }

    private function normalizeTlsCryptoMethod(array $tls) {
        $cryptoMethod = $tls['crypto_method'];

        if (is_string($cryptoMethod)) {
            $cryptoMethodArray = explode(' ', strtolower($cryptoMethod));
        } elseif (is_array($cryptoMethod)) {
            $cryptoMethodArray =& $cryptoMethod;
        } else {
            throw new \DomainException(
                sprintf('Invalid crypto method type: %s. String or array required', gettype($cryptoMethod))
            );
        }

        $bitmask = 0;
        foreach ($cryptoMethodArray as $method) {
            if (isset(self::$cryptoMethodMap[$method])) {
                $bitmask |= self::$cryptoMethodMap[$method];
            }
        }

        if (empty($bitmask)) {
            throw new \DomainException(
                'Invalid crypto method value: no valid methods found'
            );
        }

        $tls['crypto_method'] = $bitmask;

        return $tls;
    }

    /**
     * Retrieve this host's TLS connection context options
     *
     * @return array An array of stream encryption context options
     */
    public function getTlsContextArr() {
        return $this->tlsContextArr;
    }

    /**
     * Determine if this host matches the specified HostDefinition ID string
     *
     * @param string $hostId
     * @return bool Returns true if a match is found, false otherwise
     */
    public function matches($hostId) {
        if ($hostId === $this->id || $hostId === '*') {
            $isMatch = true;
        } elseif (substr($hostId, 0, 2) === '*:') {
            $portToMatch = substr($hostId, 2);
            $isMatch = ($portToMatch === '*' || $this->port == $portToMatch);
        } elseif (substr($hostId, -2) === ':*') {
            $addrToMatch = substr($hostId, 0, -2);
            $isMatch = ($addrToMatch === '*' || $this->address === $addrToMatch || $this->name === $addrToMatch);
        } else {
            $isMatch = false;
        }

        return $isMatch;
    }

    /**
     * Returns the host name
     *
     * @return string
     */
    public function __toString() {
        return $this->name;
    }

    /**
     * Simplify debug output
     *
     * @return array
     */
    public function __debugInfo() {
        $appType = is_object($this->application)
            ? get_class($this->application)
            : gettype($this->application);

        return [
            'address' => $this->address,
            'port' => $this->port,
            'name' => $this->name,
            'tls' => $this->tlsContextArr,
            'application' => $appType,
        ];
    }
}
