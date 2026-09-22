<?php

use App\Mail\Transport\SendGridApiTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/*
 * Verifies the SendGrid HTTPS API transport in isolation, against a
 * MockHttpClient rather than a real network call -- this project's whole
 * reason for existing is that Railway blocks outbound SMTP, so these tests
 * must never depend on reaching either smtp.*.net or api.sendgrid.com.
 */

function sendGridEmail(): Email
{
    return (new Email)
        ->from(new Address('sender@clsu.edu.ph', 'CLSU Infirmary Telemedicine'))
        ->to(new Address('recipient@clsu.edu.ph', 'Maria Cruz'))
        ->subject('Test subject')
        ->text('Plain text body')
        ->html('<p>HTML body</p>');
}

test('it posts to the SendGrid v3 endpoint with a bearer token and the right payload shape', function () {
    $captured = null;

    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
        $captured = [$method, $url, $options];

        return new MockResponse('', ['http_code' => 202, 'response_headers' => ['x-message-id' => 'abc123']]);
    });

    $transport = new SendGridApiTransport('sg-test-key', $client);

    $email = sendGridEmail();
    $envelope = new Envelope($email->getFrom()[0], [$email->getTo()[0]]);

    $transport->send($email, $envelope);

    [$method, $url, $options] = $captured;

    expect($method)->toBe('POST')
        ->and($url)->toBe('https://api.sendgrid.com/v3/mail/send')
        ->and($options['normalized_headers']['authorization'][0])->toContain('Bearer sg-test-key');

    $payload = json_decode($options['body'], true);

    expect($payload['from'])->toBe(['email' => 'sender@clsu.edu.ph', 'name' => 'CLSU Infirmary Telemedicine'])
        ->and($payload['personalizations'][0]['to'])->toBe([['email' => 'recipient@clsu.edu.ph', 'name' => 'Maria Cruz']])
        ->and($payload['personalizations'][0]['subject'])->toBe('Test subject')
        ->and($payload['content'])->toContain(['type' => 'text/plain', 'value' => 'Plain text body'])
        ->and($payload['content'])->toContain(['type' => 'text/html', 'value' => '<p>HTML body</p>'])
        ->and($payload)->not->toHaveKey('attachments');
});

test('cc, bcc, and reply-to are included when set', function () {
    $captured = null;

    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
        $captured = $options;

        return new MockResponse('', ['http_code' => 202]);
    });

    $transport = new SendGridApiTransport('sg-test-key', $client);

    $email = sendGridEmail()
        ->cc(new Address('cc@clsu.edu.ph'))
        ->bcc(new Address('bcc@clsu.edu.ph'))
        ->replyTo(new Address('reply@clsu.edu.ph', 'Reply Handler'));

    $envelope = new Envelope($email->getFrom()[0], [$email->getTo()[0], $email->getCc()[0], $email->getBcc()[0]]);

    $transport->send($email, $envelope);

    $payload = json_decode($captured['body'], true);

    expect($payload['personalizations'][0]['cc'])->toBe([['email' => 'cc@clsu.edu.ph']])
        ->and($payload['personalizations'][0]['bcc'])->toBe([['email' => 'bcc@clsu.edu.ph']])
        ->and($payload['reply_to'])->toBe(['email' => 'reply@clsu.edu.ph', 'name' => 'Reply Handler']);
});

test('an attachment is base64-encoded with its filename and content type', function () {
    $captured = null;

    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
        $captured = $options;

        return new MockResponse('', ['http_code' => 202]);
    });

    $transport = new SendGridApiTransport('sg-test-key', $client);

    $email = sendGridEmail()->attach('raw file content', 'notes.txt', 'text/plain');
    $envelope = new Envelope($email->getFrom()[0], [$email->getTo()[0]]);

    $transport->send($email, $envelope);

    $payload = json_decode($captured['body'], true);

    expect($payload['attachments'])->toHaveCount(1)
        ->and($payload['attachments'][0]['filename'])->toBe('notes.txt')
        ->and($payload['attachments'][0]['type'])->toBe('text/plain')
        ->and($payload['attachments'][0]['disposition'])->toBe('attachment')
        ->and(base64_decode($payload['attachments'][0]['content']))->toBe('raw file content');
});

test('a non-2xx SendGrid response is surfaced as a transport exception, not silently swallowed', function () {
    $client = new MockHttpClient(fn () => new MockResponse(
        '{"errors":[{"message":"The from address does not match a verified Sender Identity"}]}',
        ['http_code' => 403]
    ));

    $transport = new SendGridApiTransport('sg-test-key', $client);

    $email = sendGridEmail();
    $envelope = new Envelope($email->getFrom()[0], [$email->getTo()[0]]);

    expect(fn () => $transport->send($email, $envelope))
        ->toThrow(HttpTransportException::class, 'verified Sender Identity');
});

test('the transport is registered as the "sendgrid" mailer and resolves without error', function () {
    config(['mail.default' => 'sendgrid', 'mail.mailers.sendgrid.key' => 'sg-test-key']);

    $mailer = app('mail.manager')->mailer('sendgrid');

    expect($mailer)->toBeInstanceOf(\Illuminate\Mail\Mailer::class);
});
