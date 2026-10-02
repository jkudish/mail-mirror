<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

/**
 * @property int $id
 * @property string $provider_identity_id
 * @property string|null $email_address
 * @property string|null $display_name
 */
final class MailIdentity extends AccountScopedModel {}
