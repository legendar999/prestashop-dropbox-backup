<?php

declare(strict_types=1);

namespace Akvabackup\Dropbox;

/**
 * Thrown when Dropbox is not connected (missing refresh token / app credentials).
 */
final class NotConnectedException extends DropboxException
{
}
