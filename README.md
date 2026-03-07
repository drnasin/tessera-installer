# Tessera Installer

Kreiraj novi projekt jednom komandom. AI odlucuje o svemu — ti samo opisujes sto trebas.

## Instalacija

```bash
composer global require tessera/installer
```

Provjeri da je Composer `global bin` direktorij u PATH-u:
- **Windows:** `%APPDATA%\Composer\vendor\bin`
- **macOS/Linux:** `~/.composer/vendor/bin`

## Kako koristiti

```bash
tessera new moj-projekt
```

To je to. AI ce te pitati par pitanja i sve napraviti.

## Sto se dogada kad pokrenes `tessera new`

```
$ tessera new moj-restoran

╔══════════════════════════════════════╗
║        TESSERA — AI Architect        ║
║    Opisi sto trebas, AI ce odluciti  ║
╚══════════════════════════════════════╝

✓ AI: claude
Dostupni stackovi:
  ✓ Laravel + Filament (Tessera CMS)
  ✓ Node.js (Next.js / Express)
  ✓ Flutter (Mobile + Web App)
  ✓ Static Site (HTML + Tailwind)

AI: Bok! Kakav projekt pravis?
> Web stranica za restoran u Splitu, trebaju jelovnik i rezervacije

AI: Razumijem. Trebaju li vise jezika?
> Da, hrvatski i engleski

AI bira tehnologiju...
✓ Odabrano: Laravel + Filament (Tessera CMS)

Nastavljamo? [Y/n]: Y

⏳ Kreiram Laravel projekt...
✓ Laravel projekt kreiran
⏳ Instaliram Tessera pakete...
✓ Core paketi instalirani
⏳ AI konfigurira i gradi projekt...
✓ AI scaffold zavrsen

╔══════════════════════════════════════╗
║         PROJEKT JE SPREMAN!          ║
╚══════════════════════════════════════╝

  cd moj-restoran
  php artisan serve

  Stranica: http://localhost:8000
  Admin:    http://localhost:8000/admin
  Login:    admin@tessera.test / password
```

## Kako AI bira tehnologiju

Ne moras znati sto je Laravel ili Flutter. Samo opisi sto trebas:

| Sto kazes | Sto AI odabere |
|---|---|
| "web stranica za restoran" | Laravel (CMS s admin panelom) |
| "mobilna app za dostavu" | Flutter (iOS + Android) |
| "API za chat aplikaciju s 10000 korisnika" | Go (high-performance backend) |
| "SaaS dashboard s React frontendom" | Node.js (Next.js + API) |
| "landing stranica za event" | Static (HTML + Tailwind) |

AI gleda sto si opisao i sam odluci. Ako se ne slazes, mozes reci "ne, radije Laravel" i AI ce promijeniti odluku.

## Dostupni stackovi

### Laravel + Filament (potpuno autonomno)
Web stranice, CMS-ovi, e-commerce, admin paneli. AI sam postavlja sve — stranice, blokove, module, temu, SEO.

### Node.js / Next.js
API serveri, SaaS platforme, React/Vue aplikacije. AI generira strukturu i pocetni kod.

### Go
High-performance backend-i, microservisi, real-time sustavi. AI generira projekt s Chi routerom i Prisma/GORM.

### Flutter
Mobilne aplikacije (iOS + Android + Web). AI kreira projekt s Riverpod state managementom i Material 3 temom.

### Static Site
Jednostavne landing stranice bez backend-a. HTML + Tailwind + Alpine.js. Spreman za deploy na Netlify/Vercel.

## Preduvjeti

Obavezno:
- **PHP 8.2+** — `php --version`
- **Composer** — `composer --version`
- **AI CLI alat** — barem jedan od:

| Alat | Instalacija | Provjera |
|---|---|---|
| Claude | `npm install -g @anthropic-ai/claude-code` | `claude --version` |
| Codex | `npm install -g @openai/codex` | `codex --version` |
| Gemini | `npm install -g @google/gemini-cli` | `gemini --version` |

Opcionalno (ovisi o odabranom stacku):
- **Node.js 20+** — za Node.js stack i npm pakete
- **Go 1.22+** — za Go stack
- **Flutter SDK** — za Flutter stack

## Nakon kreiranja projekta

```bash
cd moj-projekt

# Pokreni dev server
php artisan serve

# AI chat u terminalu
php artisan tessera

# Direktan zahtjev
php artisan tessera "dodaj galeriju na pocetnu stranicu"

# Popravi gresku
php artisan tessera --fix

# AI pregled projekta
php artisan tessera --audit
```

U admin panelu (`/admin`) imas AI chat widget u donjem desnom kutu. Prati sto radis i nudi pomoc.

## Kako dodati novi stack

Ako si developer i zelis dodati podrsku za novu tehnologiju (npr. Python/Django):

1. Kreiraj `src/Stacks/PythonStack.php` koji implementira `StackInterface`
2. Registriraj ga u `StackRegistry::init()`
3. AI automatski zna za novi stack

```php
final class PythonStack implements StackInterface
{
    public function name(): string { return 'python'; }
    public function label(): string { return 'Python (Django)'; }
    public function description(): string { return 'Web aplikacije, API, ML...'; }
    // ... implementiraj scaffold(), preflight(), postSetup()
}
```

## Licenca

Privatni projekt.
