#!/usr/bin/env php
<?php

require __DIR__ . "/support/bootstrap.php";

use Amp\ByteStream\IteratorStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Delayed;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Http\Server\Support\Recommender;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Producer;
use Amp\Socket;
use Monolog\Logger;

// Run this script, then visit http://localhost:1337/ in your browser.

Amp\Loop::run(function () {
    $servers = [
        Socket\listen("0.0.0.0:1337"),
        Socket\listen("[::]:1337"),
    ];

    $logHandler = new StreamHandler(new ResourceOutputStream(\STDOUT));
    $logHandler->setFormatter(new ConsoleFormatter);
    $logger = new Logger('server');
    $logger->pushHandler($logHandler);

    $server = new Server($servers, new CallableRequestHandler(function (Request $request) {
        // We stream the response here, one line every 100 ms.
        return new Response(Status::OK, [
            "content-type" => "text/plain; charset=utf-8",
        ], new IteratorStream(new Producer(function (callable $emit) {
            for ($i = 0; $i < 30; $i++) {
                yield new Delayed(100);
                yield $emit("Line {$i}\r\n");
            }
        })));
    }), $logger, (new Options)->withoutCompression());

    $server->attach(new Recommender);

    yield $server->start();

    // Stop the server when SIGINT is received (this is technically optional, but it is best to call Server::stop()).
    Amp\Loop::onSignal(SIGINT, function (string $watcherId) use ($server) {
        Amp\Loop::cancel($watcherId);
        yield $server->stop();
    });
});
