<?php

namespace App\Mail\Transport;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends mail through SendGrid's HTTPS API (v3 /mail/send) instead of SMTP.
 *
 * Railway blocks outbound SMTP for this project — verified by identical
 * connection timeouts against smtp.gmail.com and smtp.sendgrid.net on port
 * 587, two unrelated providers, ruling out a Gmail-specific IP block. No
 * SMTP-based mailer can ever connect from here, SendGrid's own included.
 * This talks to SendGrid over HTTPS:443 instead, which is unaffected.
 */
final class SendGridApiTransport extends AbstractApiTransport
{
    private const ENDPOINT = 'https://api.sendgrid.com/v3/mail/send';

    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        ?HttpClientInterface $client = null,
    ) {
        parent::__construct($client);
    }

    public function __toString(): string
    {
        return 'sendgrid+api://api.sendgrid.com';
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $response = $this->client->request('POST', self::ENDPOINT, [
            'auth_bearer' => $this->apiKey,
            'json' => $this->buildPayload($email, $envelope),
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 300) {
            throw new HttpTransportException(
                \sprintf(
                    'Unable to send an email via SendGrid: "%s" (code %d).',
                    $response->getContent(false),
                    $statusCode
                ),
                $response
            );
        }

        // SendGrid returns the message id in a response header, not a body
        // (a successful send is a bare 202 with no content).
        $messageId = $response->getHeaders(false)['x-message-id'][0] ?? null;

        if ($messageId !== null) {
            $sentMessage->setMessageId($messageId);
        }

        return $response;
    }

    private function buildPayload(Email $email, Envelope $envelope): array
    {
        // Read from the message's own To/Cc/Bcc headers, not the envelope's
        // flat recipient list — the envelope only carries bare addresses for
        // routing (correct SMTP semantics), which would silently drop every
        // recipient's display name from what SendGrid actually sends.
        $payload = [
            'personalizations' => [array_filter([
                'to' => $this->formatAddresses($email->getTo()),
                'cc' => $this->formatAddresses($email->getCc()),
                'bcc' => $this->formatAddresses($email->getBcc()),
                'subject' => $email->getSubject(),
            ])],
            'from' => $this->formatAddress($envelope->getSender()),
            'content' => $this->buildContent($email),
        ];

        if ($replyTo = $email->getReplyTo()) {
            $payload['reply_to'] = $this->formatAddress($replyTo[0]);
        }

        if ($attachments = $this->buildAttachments($email)) {
            $payload['attachments'] = $attachments;
        }

        return $payload;
    }

    private function buildContent(Email $email): array
    {
        $content = [];

        if ($text = $email->getTextBody()) {
            $content[] = ['type' => 'text/plain', 'value' => $text];
        }

        if ($html = $email->getHtmlBody()) {
            $content[] = ['type' => 'text/html', 'value' => $html];
        }

        // SendGrid rejects a request with no content entries at all; every
        // notification in this app sets a body, but a bare RawMessage
        // technically could not, and that should not crash the request.
        return $content ?: [['type' => 'text/plain', 'value' => '']];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildAttachments(Email $email): array
    {
        return array_map(function (DataPart $part): array {
            $disposition = $part->getPreparedHeaders()->get('Content-Disposition')?->getValue() ?: 'attachment';

            // getBody(), not bodyToString(): the latter already applies the
            // part's own MIME transfer encoding (base64 by default for a
            // DataPart), so encoding it again here would double-encode.
            // SendGrid's API wants exactly one layer of base64 over the raw
            // bytes, independent of whatever transfer encoding Symfony chose.
            $attachment = [
                'content' => base64_encode($part->getBody()),
                'type' => $part->getContentType(),
                'filename' => $part->getFilename() ?? 'attachment',
                'disposition' => $disposition,
            ];

            if ($disposition === 'inline' && $part->hasContentId()) {
                $attachment['content_id'] = $part->getContentId();
            }

            return $attachment;
        }, $email->getAttachments());
    }

    /**
     * @param  Address[]  $addresses
     * @return array<int, array<string, string>>
     */
    private function formatAddresses(array $addresses): array
    {
        return array_map($this->formatAddress(...), $addresses);
    }

    /**
     * @return array<string, string>
     */
    private function formatAddress(Address $address): array
    {
        return array_filter([
            'email' => $address->getAddress(),
            'name' => $address->getName() ?: null,
        ]);
    }
}
