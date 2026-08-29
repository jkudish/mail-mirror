<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Enums;

enum MailImportCode: string
{
    case AuthenticationFailed = 'authentication_failed';
    case HistoryExpired = 'history_expired';
    case MalformedPayload = 'malformed_payload';
    case MessageUnavailable = 'message_unavailable';
    case PermissionDenied = 'permission_denied';
    case ProviderUnavailable = 'provider_unavailable';
    case RateLimited = 'rate_limited';
    case StateMismatch = 'state_mismatch';
    case SyntheticFailure = 'synthetic_failure';
    case UnexpectedFailure = 'unexpected_failure';

    public static function normalize(self|string $code): self
    {
        return $code instanceof self ? $code : (self::tryFrom($code) ?? self::UnexpectedFailure);
    }

    public function summary(): string
    {
        return match ($this) {
            self::AuthenticationFailed => 'The provider connection could not be authenticated.',
            self::HistoryExpired => 'The provider history window must be inventoried again.',
            self::MalformedPayload => 'The provider returned a malformed message payload.',
            self::MessageUnavailable => 'The provider message is currently unavailable.',
            self::PermissionDenied => 'The provider denied access to the message.',
            self::ProviderUnavailable => 'The provider is temporarily unavailable.',
            self::RateLimited => 'The provider requested a bounded retry.',
            self::StateMismatch => 'The provider state must be inventoried again.',
            self::SyntheticFailure => 'The provider message could not be imported.',
            self::UnexpectedFailure => 'The provider message could not be retrieved safely.',
        };
    }
}
