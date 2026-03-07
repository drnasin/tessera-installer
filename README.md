# Tessera Installer

AI-Native CMS Installer — opisi sto trebas, AI ce izgraditi projekt.

## Instalacija

```bash
composer global require tessera/installer
```

## Koristi

```bash
# Novi projekt
tessera new moj-restoran

# Provjeri AI alate
tessera tools
```

## Kako radi

1. `tessera new moj-projekt` — pokrece interaktivni wizard
2. Odgovaras na par pitanja (sto klijent radi, treba li shop, jezici...)
3. AI planira arhitekturu i predlaze strukturu
4. Ti potvrdujes plan
5. AI kreira Laravel projekt, instalira pakete, konfigurira sve
6. AI generira stranice, blokove, temu, admin panel
7. Gotovo — `php artisan serve` i provjeri

## Preduvjeti

- PHP 8.2+
- Composer
- Node.js + NPM (za Vite/Tailwind)
- Barem jedan AI CLI alat:
  - `claude` (Anthropic Claude Code)
  - `gemini` (Google Gemini CLI)
  - `codex` (OpenAI Codex CLI)

## Nakon kreiranja

```bash
cd moj-projekt
php artisan serve

# Stranica: http://localhost:8000
# Admin:    http://localhost:8000/admin

# Za dalje promjene:
php artisan tessera "dodaj galeriju na pocetnu stranicu"
```
