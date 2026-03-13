<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\AiTool;
use Tessera\Installer\Console;
use Tessera\Installer\Memory;
use Tessera\Installer\StepRunner;
use Tessera\Installer\SystemInfo;

/**
 * Static site (HTML + Tailwind + Alpine.js).
 * For simple landing pages that don't need a backend.
 */
final class StaticStack implements StackInterface
{
    private StepRunner $steps;

    private string $fullPath;

    public function name(): string
    {
        return 'static';
    }

    public function label(): string
    {
        return 'Static Site (HTML + Tailwind)';
    }

    public function description(): string
    {
        return 'Simple landing pages, portfolio sites, event pages, '
            . 'coming soon pages — no backend, no database. '
            . 'Best for: quick one-off pages, campaigns, '
            . 'personal portfolio sites, event invitations. '
            . 'Stack: HTML5, Tailwind CSS (latest), Alpine.js, Vite. Deploy: Netlify/Vercel/GitHub Pages.';
    }

    public function preflight(): array
    {
        $missing = [];

        $npm = Console::execSilent('npm --version');
        if ($npm['exit'] !== 0) {
            $missing[] = 'Node.js + npm (https://nodejs.org)';
        }

        return ['ready' => empty($missing), 'missing' => $missing];
    }

    public function scaffold(string $directory, array $requirements, AiTool $ai, SystemInfo $system, Memory $memory): bool
    {
        $this->fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;
        $this->steps = new StepRunner($ai, $this->fullPath);

        $desc = $requirements['description'] ?? 'Landing page';
        $designStyle = $requirements['design_style'] ?? 'modern, clean';
        $designColors = $requirements['design_colors'] ?? 'use appropriate colors for the business type';
        $langs = implode(', ', $requirements['languages'] ?? ['en']);
        $nodeVersion = $this->detectVersions();
        $systemContext = $system->buildAiContext();

        // Check if we're resuming
        $resuming = is_dir($this->fullPath) && is_file($this->fullPath . '/.tessera/state.json');

        Console::line();
        if ($resuming) {
            Console::bold('Resuming build — skipping completed steps...');
        } else {
            Console::bold('Building your site — this takes about 2-3 minutes.');
        }
        Console::line();

        if (! @mkdir($this->fullPath, 0755, true) && ! is_dir($this->fullPath)) {
            Console::error("Could not create directory: {$this->fullPath}");

            return false;
        }

        $memory->init($directory, 'static', $requirements, $system->buildAiContext());

        // Step 1: AI scaffold — senior dev reasoning
        if ($memory->isStepDone('scaffold')) {
            Console::success('[1/3] Creating website (already done)');
        } else {
        $memory->startStep('scaffold');
        $this->steps->runAi(
            name: '[1/3] Creating website',
            prompt: <<<PROMPT
You are a SENIOR frontend developer building a static website from scratch.
Think carefully about what THIS specific site needs before writing any code.

{$systemContext}

RUNTIME: {$nodeVersion}
PROJECT: {$desc}
LANGUAGES: {$langs}
DESIGN STYLE: {$designStyle}
DESIGN COLORS: {$designColors}

STEP 1 — THINK (do not skip):
- What pages does this site need? (A restaurant needs: Home, Menu, About, Contact.
  A portfolio needs: Home, Projects, About. An event needs: just one epic landing page.)
- What sections does each page need? (Hero, features, testimonials, pricing, FAQ, CTA, contact form?)
- What interactive elements? (Mobile menu, FAQ accordion, image gallery, scroll animations?)
- Does it need a contact form? (Use formspree.io or netlify forms — no backend)
- Does it need social media links? Google Maps embed?

STEP 2 — CREATE:
1. package.json with Vite + Tailwind CSS (latest) + Alpine.js
2. vite.config.js configured for multi-page (if multiple HTML files)
3. HTML pages — each one COMPLETE with:
   - Semantic HTML5 structure
   - Tailwind utility classes for ALL styling
   - Alpine.js for interactivity (mobile menu, accordions, tabs)
   - REALISTIC content for this business — NO lorem ipsum, NO placeholder text
   - Content language: {$langs}
4. src/style.css — Tailwind imports + any custom styles
5. src/main.js — Alpine.js init
6. Deploy config: netlify.toml AND vercel.json (developer picks which to use)

DESIGN — make it look like a REAL website a client would pay for:
- Style: {$designStyle}
- Colors: {$designColors}
- Mobile-first responsive design (test every breakpoint mentally)
- Hero: large, striking, with clear value proposition and CTA
- Typography: proper hierarchy (text-5xl → text-xl), good line-height, readable
- Spacing: generous (py-16 to py-24 between sections)
- Cards: rounded-xl, shadow on hover, smooth transitions
- Navigation: sticky, hamburger on mobile (Alpine.js x-data/x-show)
- Footer: dark background, organized in columns
- Images: use picsum.photos or placeholder.co for placeholder images with correct dimensions
- SEO: meta title, description, OG tags, structured data (JSON-LD) for the business type
- Accessibility: WCAG AA (alt tags, focus states, aria labels, contrast)
- Favicon: link to a placeholder or generate inline SVG favicon

CONTENT: Write like a professional copywriter. Compelling headlines,
clear value propositions, realistic testimonials with local-sounding names.
PROMPT,
            verify: function (): ?string {
                if (is_file($this->fullPath . '/index.html')) {
                    return null;
                }
                if (is_file($this->fullPath . '/package.json')) {
                    return null;
                }

                return 'No index.html or package.json created';
            },
            timeout: 300,
        );
        $memory->completeStep('scaffold');
        } // end if !isStepDone('scaffold')

        // Step 2: Validate and polish
        if ($memory->isStepDone('polish')) {
            Console::success('[2/3] Validating and polishing (already done)');
        } else {
        $memory->startStep('polish');
        $this->steps->runAi(
            name: '[2/3] Validating and polishing',
            prompt: <<<PROMPT
Review the generated static site thoroughly. You are a senior frontend developer doing a code review.

Check and fix:
1. ALL HTML files are valid and complete (no unclosed tags, no missing elements)
2. ALL internal links between pages work (href paths are correct)
3. Meta tags present on every page (title, description, OG image, OG title, OG description)
4. Mobile responsiveness works (no horizontal scroll, readable text, proper touch targets)
5. Alpine.js components work (mobile menu opens/closes, accordions toggle)
6. Tailwind classes are correct (no typos, proper responsive prefixes)
7. Accessibility: all images have alt text, buttons have labels, links are descriptive
8. Contact form (if exists) has action pointing to formspree or netlify forms
9. Fix any issues found — do not just list them
PROMPT,
            verify: null,
            skippable: true,
            timeout: 120,
        );
        $memory->completeStep('polish');
        } // end if !isStepDone('polish')

        // Step 3: SETUP.md — developer handoff
        if ($memory->isStepDone('setup_md')) {
            Console::success('[3/3] Generating setup instructions (already done)');
        } else {
        $memory->startStep('setup_md');
        $this->steps->runAi(
            name: '[3/3] Generating setup instructions',
            prompt: <<<PROMPT
Read the site you just built. Generate a SETUP.md file in the project root.

PROJECT: {$desc}

SETUP.md must include:

1. QUICK START — npm install, npm run dev, preview URL
2. EDITING CONTENT — explain where to find and edit:
   - Text content (which HTML file, which section)
   - Images (where to replace placeholder images)
   - Colors (where the Tailwind config or CSS variables are)
   - Contact form email (where to change the form action/email)
3. CONTACT FORM SETUP (if exists):
   - How to set up Formspree or Netlify Forms (step by step)
   - How to test it works
4. DEPLOYMENT — step by step for:
   - Netlify: connect repo, auto-deploy, custom domain
   - Vercel: connect repo, auto-deploy, custom domain
   - GitHub Pages: build and deploy
   - Manual: npm run build, upload dist/ folder
5. CUSTOM DOMAIN — how to connect a custom domain on each platform
6. GOOGLE ANALYTICS / TRACKING — where to add the tracking code
7. COMMON CHANGES:
   - How to add a new page
   - How to change fonts
   - How to add/change social media links
   - How to change the color scheme

Write for someone who may have NEVER deployed a website before.
PROMPT,
            verify: fn (): ?string => is_file($this->fullPath . '/SETUP.md') ? null : 'SETUP.md not created',
            skippable: true,
            timeout: 120,
        );
        $memory->completeStep('setup_md');
        } // end if !isStepDone('setup_md')

        $this->steps->printSummary();

        return true;
    }

    public function postSetup(string $directory): bool
    {
        $fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;

        if (file_exists($fullPath . '/package.json')) {
            Console::spinner('Installing npm packages...');
            Console::exec('npm install', $fullPath);
        }

        return true;
    }

    public function completionInfo(string $directory): array
    {
        return [
            'commands' => [
                "cd {$directory}",
                'npm run dev',
            ],
            'urls' => [
                'Dev' => 'http://localhost:5173',
                'Build' => 'npm run build',
                'Setup guide' => 'SETUP.md',
            ],
        ];
    }

    private function detectVersions(): string
    {
        $node = Console::execSilent('node --version');
        if ($node['exit'] === 0) {
            return 'Node.js ' . trim($node['output']);
        }

        return 'Node.js (latest)';
    }
}
