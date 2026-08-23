
## 🔧 Blockierte Composer-Majors (2026-08-23)
- **guzzle 8** (transitiv): blockiert durch `league/oauth2-client 2.9.0` (erlaubt nur guzzle ^6/7; 3.x nur dev). Geliefert via `league/oauth2-google 5.0.0`. Ersatz (rohe guzzle-Calls) nicht ratsam — `league/oauth2-client` ist der PHP-Standard. Warten auf stabile `league/oauth2-client 3.0`. form2email benötigt guzzle 8 nicht.
