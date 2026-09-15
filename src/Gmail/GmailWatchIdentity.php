<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Gmail;

use InvalidArgumentException;

final readonly class GmailWatchIdentity
{
    public string $topic;

    public string $subscription;

    public function __construct(
        public string $projectId,
        string $topicId,
        string $subscriptionId,
    ) {
        if (! preg_match('/^[a-z][a-z0-9-]{4,61}[a-z0-9]$/', $projectId)
            || ! self::validResourceId($topicId) || ! self::validResourceId($subscriptionId)) {
            throw new InvalidArgumentException('The Pub/Sub resource identity is invalid.');
        }

        $this->topic = "projects/{$projectId}/topics/{$topicId}";
        $this->subscription = "projects/{$projectId}/subscriptions/{$subscriptionId}";
    }

    public static function fromConfiguration(): self
    {
        $project = config('mail-mirror.gmail.pubsub.project_id');
        $topic = config('mail-mirror.gmail.pubsub.topic_id');
        $subscription = config('mail-mirror.gmail.pubsub.subscription_id');

        if (! is_string($project) || ! is_string($topic) || ! is_string($subscription)) {
            throw new InvalidArgumentException('The Pub/Sub resource identity is invalid.');
        }

        return new self($project, $topic, $subscription);
    }

    public function equals(self $other): bool
    {
        return $this->projectId === $other->projectId
            && $this->topic === $other->topic
            && $this->subscription === $other->subscription;
    }

    private static function validResourceId(string $value): bool
    {
        return strlen($value) >= 3 && strlen($value) <= 255
            && preg_match('/^[A-Za-z][A-Za-z0-9._~+%-]+$/', $value) === 1
            && ! str_starts_with(strtolower($value), 'goog');
    }
}
