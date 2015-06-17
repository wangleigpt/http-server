<?php

namespace Aerys;

class StandardResponse implements Response {
    private $codec;
    private $state = self::NONE;
    private $headers = [
        ":status" => 200,
        ":reason" => null
    ];
    private $cookies = [];

    /**
     * @param \Generator $codec
     */
    public function __construct(\Generator $codec) {
        $this->codec = $codec;
    }

    /**
     * @return array
     */
    public function __debugInfo(): array {
        return $this->headers;
    }

    /**
     * {@inheritDoc}
     * @throws \LogicException If output already started
     * @return self
     */
    public function setStatus(int $code): Response {
        if ($this->state & self::STARTED) {
            throw new \LogicException(
                "Cannot set status code; output already started"
            );
        }
        assert(($code >= 100 && $code <= 599), "Invalid HTTP status code [100-599] expected");
        $this->headers[":status"] = $code;

        return $this;
    }

    /**
     * {@inheritDoc}
     * @throws \LogicException If output already started
     * @return self
     */
    public function setReason(string $phrase): Response {
        if ($this->state & self::STARTED) {
            throw new \LogicException(
                "Cannot set reason phrase; output already started"
            );
        }
        assert($this->isValidReasonPhrase($phrase), "Invalid reason phrase: {$phrase}");
        $this->headers[":reason"] = $phrase;

        return $this;
    }

    /**
     * @TODO Validate reason phrase against RFC7230 ABNF
     * @link https://tools.ietf.org/html/rfc7230#section-3.1.2
     */
    private function isValidReasonPhrase(string $phrase): bool {
        // reason-phrase  = *( HTAB / SP / VCHAR / obs-text )
        return true;
    }

    /**
     * {@inheritDoc}
     * @throws \LogicException If output already started
     * @return self
     */
    public function addHeader(string $field, string $value): Response {
        if ($this->state & self::STARTED) {
            throw new \LogicException(
                "Cannot add header; output already started"
            );
        }
        assert($this->isValidHeaderField($field), "Invalid header field: {$field}");
        assert($this->isValidHeaderValue($value), "Invalid header value: {$value}");
        $this->headers[strtolower($field)][] = $value;

        return $this;
    }

    /**
     * @TODO Validate field name against RFC7230 ABNF
     * @link https://tools.ietf.org/html/rfc7230#section-3.2
     */
    private function isValidHeaderField(string $field): bool {
        // field-name     = token
        return true;
    }

    /**
     * @TODO Validate field name against RFC7230 ABNF
     * @link https://tools.ietf.org/html/rfc7230#section-3.2
     */
    private function isValidHeaderValue(string $value): bool {
        // field-value    = *( field-content / obs-fold )
        // field-content  = field-vchar [ 1*( SP / HTAB ) field-vchar ]
        // field-vchar    = VCHAR / obs-text
        //
        // obs-fold       = CRLF 1*( SP / HTAB )
        //                ; obsolete line folding
        //                ; see Section 3.2.4
        return true;
    }

    /**
     * {@inheritDoc}
     * @throws \LogicException If output already started
     * @return self
     */
    public function setHeader(string $field, string $value): Response {
        if ($this->state & self::STARTED) {
            throw new \LogicException(
                "Cannot set header; output already started"
            );
        }
        assert($this->isValidHeaderField($field), "Invalid header field: {$field}");
        assert($this->isValidHeaderValue($value), "Invalid header value: {$value}");
        $this->headers[strtolower($field)] = [$value];

        return $this;
    }

    /**
     * {@inheritDoc}
     * @throws \LogicException If output already started
     * @return self
     */
    public function setCookie(string $name, string $value, array $flags = []): Response {
        if ($this->state & self::STARTED) {
            throw new \LogicException(
                "Cannot set header; output already started"
            );
        }

        // @TODO assert() valid $name / $value / $flags
        $this->cookies[$name] = [$value, $flags];

        return $this;
    }

    /**
     * Send the specified response entity body
     *
     * @param string $body The full response entity body
     * @throws \LogicException If response output already started
     * @throws \Aerys\ClientException If the client has already disconnected
     * @return self
     */
    public function send(string $body): Response {
        if ($this->state & self::ENDED) {
            throw new \LogicException(
                "Cannot send: response already sent"
            );
        } elseif ($this->state & self::STREAMING) {
            throw new \LogicException(
                "Cannot send: response already streaming"
            );
        } else {
            return $this->end($body);
        }
    }

    /**
     * Stream partial entity body data
     *
     * If response output has not yet started headers will also be sent
     * when this method is invoked.
     *
     * @param string $partialBody
     * @throws \LogicException If response output already complete
     * @return self
     */
    public function stream(string $partialBody): Response {
        if ($this->state & self::ENDED) {
            throw new \LogicException(
                "Cannot stream: response already sent"
            );
        }

        if (!($this->state & self::STARTED)) {
            $this->setCookies();
            // A * (as opposed to a numeric length) indicates "streaming entity content"
            $headers = $this->headers;
            $headers[":aerys-entity-length"] = "*";
            $this->codec->send($headers);
        }

        $this->codec->send($partialBody);

        // Don't update the state until *AFTER* the codec operation so that if
        // it throws we can handle InternalFilterException appropriately in the server.
        $this->state = self::STREAMING|self::STARTED;

        return $this;
    }

    /**
     * Request that any buffered data be flushed to the client
     *
     * This method only makes sense when streaming output via Response::stream().
     * Invoking it before calling stream() or after send()/end() is a logic error.
     *
     * @throws \LogicException If invoked before stream() or after send()/end()
     * @return self
     */
    public function flush(): Response {
        if ($this->state & self::ENDED) {
            throw new \LogicException(
                "Cannot flush: response already sent"
            );
        } elseif ($this->state & self::STARTED) {
            $this->codec->send(false);
        } else {
            throw new \LogicException(
                "Cannot flush: response output not started"
            );
        }

        return $this;
    }

    /**
     * Signify the end of streaming response output
     *
     * User applications are NOT required to call Response::end() as the server
     * will handle this automatically as needed.
     *
     * Passing the optional $finalBody is equivalent to the following:
     *
     *     $response->stream($finalBody);
     *     $response->end();
     *
     * @param string $finalBody Optional final body data to send
     * @return self
     */
    public function end(string $finalBody = null): Response {
        if ($this->state & self::ENDED) {
            if (isset($finalBody)) {
                throw new \LogicException(
                    "Cannot send body data: response output already ended"
                );
            }
            return $this;
        }

        if (!($this->state & self::STARTED)) {
            $this->setCookies();
            // An @ (as opposed to a numeric length) indicates "no entity content"
            $entityValue = isset($finalBody) ? strlen($finalBody) : "@";
            $headers = $this->headers;
            $headers[":aerys-entity-length"] = $entityValue;
            $this->codec->send($headers);
        }

        $this->codec->send($finalBody);
        if (isset($finalBody)) {
            $this->codec->send(null);
        }

        // Update the state *AFTER* the codec operation so that if it throws
        // we can handle things appropriately in the server.
        $this->state = self::ENDED|self::STARTED;

        return $this;
    }

    private function setCookies() {
        foreach ($this->cookies as $name => list($value, $flags)) {
            $cookie = "$name=$value";

            foreach ($flags as $name => $value) {
                if (\is_int($name)) {
                    $cookie .= "; $value";
                } else {
                    $cookie .= "; $name=$value";
                }
            }

            $this->headers["set-cookie"][] = $cookie;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function state(): int {
        return $this->state;
    }
}
