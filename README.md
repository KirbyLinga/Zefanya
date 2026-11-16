# Zefanya — Web and Mobile

| Folder | What it is |
|---|---|
| `WebZefanya/` | Laravel web app and Sanctum API (buyer, seller, logistics, rider, admin) |
| `mobile/` | Flutter app (buyer and rider) that calls the WebZefanya API |

## WebZefanya
```
cd WebZefanya
cp .env.example .env   # then fill in your own values
composer install && npm install && npm run build
php artisan key:generate
```

## Mobile (pointed at a WebZefanya server)
```
cd mobile
flutter pub get
flutter build apk --release --dart-define=zefanya.backend=webzefanya --dart-define=zefanya.webzefanya.apiBaseUrl=https://YOUR-HOST/api --dart-define=zefanya.webzefanya.allowWrites=true
```
