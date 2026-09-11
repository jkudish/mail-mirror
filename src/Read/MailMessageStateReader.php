<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailContainer;
use Jkudish\MailMirror\Models\MailMessage;
use Jkudish\MailMirror\Models\MailMessageContainerMembership;
use UnexpectedValueException;

final readonly class MailMessageStateReader
{
    private const int MAX_NATIVE_ITEMS = 10_000;

    public function read(MailAccount $account, MailMessage $message): MailMessageState
    {
        if ($message->mail_account_id !== $account->id) {
            throw new InvalidArgumentException('The mail message does not belong to the supplied account.');
        }

        $message = MailMessage::query()->forAccount($account)->whereKey($message->id)->first();
        if (! $message instanceof MailMessage) {
            throw new InvalidArgumentException('The mail message does not belong to the supplied account.');
        }

        $memberships = MailMessageContainerMembership::query()->forAccount($account)
            ->where('mail_message_id', $message->id)
            ->with('container')
            ->orderBy('id')
            ->get();
        if ($memberships->count() > self::MAX_NATIVE_ITEMS) {
            throw new UnexpectedValueException('The provider container state exceeds its bounded size.');
        }
        $containers = [];
        foreach ($memberships as $membership) {
            $container = $membership->container;
            if (! $container instanceof MailContainer || $container->mail_account_id !== $account->id) {
                throw new UnexpectedValueException('A container membership crosses its account boundary.');
            }
            $containers[] = [
                'id' => $container->id,
                'provider_id' => $this->stringAttribute($container, 'provider_container_id'),
                'name' => $this->stringAttribute($container, 'name'),
                'kind' => $this->nullableStringAttribute($container, 'kind'),
            ];
        }

        $metadata = is_array($message->provider_metadata) ? $message->provider_metadata : [];
        $keywords = $this->keywords($metadata['keywords'] ?? null);
        $flags = $this->flags(
            $account->driver,
            $metadata['mailbox_state'] ?? null,
            $keywords,
            array_key_exists('keywords', $metadata),
            $containers,
        );

        return new MailMessageState(
            unread: $flags['unread'],
            flagged: $flags['flagged'],
            draft: $flags['draft'],
            sent: $flags['sent'],
            spam: $flags['spam'],
            trash: $flags['trash'],
            keywords: $keywords,
            containers: $containers,
            sourceVersion: $this->sourceVersion($message, $memberships->values()->all()),
        );
    }

    /** @param array<int, MailMessageContainerMembership> $memberships */
    private function sourceVersion(MailMessage $message, array $memberships): DateTimeImmutable
    {
        $versions = [$message->getAttribute('updated_at')];
        foreach ($memberships as $membership) {
            $versions[] = $membership->getAttribute('updated_at');
            $versions[] = $membership->container?->getAttribute('updated_at');
        }
        $versions = array_values(array_filter($versions, fn (mixed $version): bool => $version instanceof DateTimeInterface));
        usort($versions, fn (DateTimeInterface $left, DateTimeInterface $right): int => $right <=> $left);

        $version = $versions[0] ?? null;
        if (! $version instanceof DateTimeInterface) {
            throw new UnexpectedValueException('The provider mailbox state has no durable version.');
        }

        return DateTimeImmutable::createFromInterface($version);
    }

    /** @return list<string> */
    private function keywords(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || count($value) > self::MAX_NATIVE_ITEMS) {
            throw new UnexpectedValueException('The provider keyword state is malformed.');
        }

        $keywords = [];
        foreach ($value as $keyword => $enabled) {
            if (! is_string($keyword) || $keyword === '' || mb_strlen($keyword) > 255 || $enabled !== true) {
                throw new UnexpectedValueException('The provider keyword state is malformed.');
            }
            $keywords[] = $keyword;
        }
        sort($keywords);

        return $keywords;
    }

    /**
     * @param  list<string>  $keywords
     * @param  list<array{id: int, provider_id: string, name: string, kind: string|null}>  $containers
     * @return array{unread: bool, flagged: bool, draft: bool, sent: bool, spam: bool, trash: bool}
     */
    private function flags(MailDriver $driver, mixed $value, array $keywords, bool $hasKeywordState, array $containers): array
    {
        $providerIds = array_map(fn (array $container): string => strtoupper($container['provider_id']), $containers);
        $kinds = array_map(fn (array $container): string => strtolower($container['kind'] ?? ''), $containers);

        if ($value !== null) {
            if (! is_array($value)) {
                throw new UnexpectedValueException('The normalized mailbox state is malformed.');
            }
            $keys = ['unread', 'draft', 'sent', 'spam', 'trash'];
            foreach ($keys as $key) {
                if (! array_key_exists($key, $value) || ! is_bool($value[$key])) {
                    throw new UnexpectedValueException('The normalized mailbox state is malformed.');
                }
            }
            if ((! array_key_exists('flagged', $value) && $driver !== MailDriver::Gmail)
                || (array_key_exists('flagged', $value) && ! is_bool($value['flagged']))) {
                throw new UnexpectedValueException('The normalized mailbox state is malformed.');
            }

            if ($driver === MailDriver::Gmail) {
                return [
                    'unread' => $value['unread'],
                    'flagged' => $value['flagged'] ?? in_array('STARRED', $providerIds, true),
                    'draft' => $value['draft'],
                    'sent' => $value['sent'],
                    'spam' => $value['spam'],
                    'trash' => $value['trash'],
                ];
            }
        }

        if ($driver === MailDriver::Jmap) {
            return [
                'unread' => $hasKeywordState && ! in_array('$seen', $keywords, true),
                'flagged' => in_array('$flagged', $keywords, true),
                'draft' => in_array('$draft', $keywords, true) || in_array('drafts', $kinds, true),
                'sent' => in_array('sent', $kinds, true),
                'spam' => array_intersect(['junk', 'spam'], $kinds) !== [],
                'trash' => in_array('trash', $kinds, true),
            ];
        }

        return [
            'unread' => in_array('UNREAD', $providerIds, true),
            'flagged' => in_array('STARRED', $providerIds, true),
            'draft' => in_array('DRAFT', $providerIds, true),
            'sent' => in_array('SENT', $providerIds, true),
            'spam' => in_array('SPAM', $providerIds, true),
            'trash' => in_array('TRASH', $providerIds, true),
        ];
    }

    private function stringAttribute(MailContainer $container, string $attribute): string
    {
        $value = $container->getAttribute($attribute);
        if (! is_string($value)) {
            throw new UnexpectedValueException('A provider container has malformed state.');
        }

        return $value;
    }

    private function nullableStringAttribute(MailContainer $container, string $attribute): ?string
    {
        $value = $container->getAttribute($attribute);
        if ($value !== null && ! is_string($value)) {
            throw new UnexpectedValueException('A provider container has malformed state.');
        }

        return $value;
    }
}
