# Gestionale Telefonia (PHP 8.1+)

Gestionale web minimale per negozio di telefonia, pensato per essere avviato velocemente senza dipendenze esterne. Architettura MVC-light con PDO, autenticazione sicura e gestione stock ICCID.

## Prerequisiti
- PHP 8.1 o superiore con estensione PDO MySQL attiva
- MySQL / MariaDB
- Web server (Apache/Nginx) oppure `php -S` per sviluppo locale

## Setup rapido
1. Clona o copia il progetto nella cartella del web server.
2. Crea il database e le tabelle:
   ```bash
   mysql -u root -p < migrations/create_db.sql
   ```
3. Aggiorna `config/config.php` con le tue credenziali MySQL.
4. Crea un utente admin via SQL:
   ```sql
   INSERT INTO users (username, password_hash, role_id, fullname)
   VALUES ('admin', '$2y$10$examplehashqui', 1, 'Admin');
   ```
   Genera l'hash da terminale PHP: `php -r "echo password_hash('tuaPassword', PASSWORD_DEFAULT);"`
5. Avvia il server di sviluppo:
   ```bash
   php -S 127.0.0.1:8000 -t public
   ```
6. Visita [http://127.0.0.1:8000](http://127.0.0.1:8000) e accedi con l'utente creato.

## Funzionalità incluse
- Login con ruoli (`admin`, `cassiere`), session hardening con `session_regenerate_id`
- Import CSV ICCID (validazione lunghezza 19-20 cifre, transazioni, gestione duplicati)
- Magazzino ICCID con stati `InStock`, `Reserved`, `Sold`
- Creazione vendite in transazione, scarico automatico ICCID e log in `audit_log`
- Stampa scontrino HTML pronto per stampa termica
- Layout responsive con sidebar collassabile (HTML5/CSS/JS vanilla)

## Struttura cartelle
```
public/            # entry point e asset
app/Controllers    # logica di presentazione
app/Services       # logica applicativa (Auth, ICCID, Sales)
app/Models         # value object semplici
app/Helpers        # utility (es. Validator)
config/            # configurazioni applicative e database
migrations/        # script SQL
views/             # viste PHP
storage/uploads    # spazio per file importati (se necessario)
logs/              # spazio per log applicativi
```

## Note operative
- Tutte le query usano prepared statement PDO.
- Password salvate con `password_hash()` / `password_verify()`.
- I controller sono pensati per essere semplici shim fra viste e servizi.
- `iccid_example.csv` offre un template pronto per importare gli ICCID.

## Possibili miglioramenti
1. **Migrazioni automatizzate:** integrare strumenti come Phinx o Laravel Schema.
2. **ORM / Query Builder:** Doctrine, Eloquent o Atlas per ridurre SQL manuale.
3. **Templating engine:** Twig o Plates per separare meglio logica e viste.
4. **Sicurezza avanzata:** token CSRF, rate limiting, validazione input lato server più estesa.
5. **API REST / SPA:** esporre endpoint JSON per un frontend moderno in futuro.
6. **Gestione sessioni distribuite:** spostare le sessioni su Redis/Memcached in produzione.

Happy coding! ✨
