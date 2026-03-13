<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Task complexity level — determines which AI tool/model to use.
 *
 * SIMPLE:  Fast, cheap model. Directory setup, config publishing, SETUP.md generation.
 * MEDIUM:  Balanced model. Test generation, test fixing, content seeding.
 * COMPLEX: Best reasoning model. Architecture, models, theme design, admin panel.
 */
enum Complexity: string
{
    case SIMPLE = 'simple';
    case MEDIUM = 'medium';
    case COMPLEX = 'complex';
}
