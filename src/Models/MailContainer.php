<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

/**
 * @property int $id
 * @property string $provider_container_id
 * @property string $name
 * @property string|null $kind
 */
final class MailContainer extends AccountScopedModel {}
