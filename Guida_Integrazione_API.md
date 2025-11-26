# Guida all'Integrazione API del Gestionale Telefonia

## Introduzione

Questa guida descrive come integrare l'API REST del Gestionale Telefonia nel tuo gestionale. L'API permette di sincronizzare dati come clienti, prodotti, vendite, offerte, richieste e altro.

## Autenticazione

L'API supporta autenticazione via token. Ottieni un token tramite login API, poi includilo in ogni richiesta.

### Login API

- **POST** `/public/index.php?page=api/auth`: Login per ottenere token.
  - Body JSON: `{"username": "tuo_user", "password": "tua_pass"}`
  - Risposta: `{"success": true, "token": "session_id", "user": {...}}`

### Uso del Token

Includi header `Authorization: Bearer <token>` in ogni richiesta API.

Esempio in PHP:

```php
$token = // ottieni da login
$client = new GuzzleHttp\Client([
    'headers' => ['Authorization' => 'Bearer ' . $token]
]);
```

## Endpoint API

Tutti gli endpoint sono accessibili via `https://tuosito.com/public/index.php?page=<endpoint>`

### 1. Clienti (`api/customers`)

- **GET** `/public/index.php?page=api/customers`: Lista tutti i clienti.
  - Risposta: `{"success": true, "data": [...]}`

- **GET** `/public/index.php?page=api/customers&id=1`: Dettagli cliente specifico.
  - Risposta: `{"success": true, "data": {...}}` o 404 se non trovato.

- **POST** `/public/index.php?page=api/customers`: Crea nuovo cliente.
  - Body JSON: `{"fullname": "Nome", "email": "email@example.com", "phone": "123456", "tax_code": "ABC123", "note": "Nota"}`
  - Risposta: `{"success": true, "id": 123}` o errori.

- **PUT** `/public/index.php?page=api/customers&id=1`: Aggiorna cliente.
  - Body JSON: stesso di POST.
  - Risposta: `{"success": true}` o errori.

- **DELETE** `/public/index.php?page=api/customers&id=1`: Elimina cliente.
  - Risposta: `{"success": true}` o errori.

### 2. Prodotti (`api/products`)

- **GET** `/public/index.php?page=api/products`: Lista prodotti attivi.
- **GET** `/public/index.php?page=api/products&id=1`: Dettagli prodotto.
- **POST** `/public/index.php?page=api/products`: Crea prodotto (body JSON con dati prodotto).
- **PUT** `/public/index.php?page=api/products&id=1`: Aggiorna prodotto.
- **DELETE** `/public/index.php?page=api/products&id=1`: Elimina prodotto.

### 3. Vendite (`api/sales`)

- **GET** `/public/index.php?page=api/sales`: Lista vendite (usa `page` e `per_page` per paginazione).
- **GET** `/public/index.php?page=api/sales&id=1`: Dettagli vendita.
- **POST** `/public/index.php?page=api/sales`: Crea vendita (body JSON con dati vendita).

### 4. Offerte (`api/offers`)

- **GET** `/public/index.php?page=api/offers`: Lista offerte attive.

### 5. Richieste Prodotti (`api/product_requests`)

- **GET** `/public/index.php?page=api/product_requests`: Lista richieste.
- **GET** `/public/index.php?page=api/product_requests&id=1`: Dettagli richiesta.
- **PUT** `/public/index.php?page=api/product_requests&id=1`: Aggiorna richiesta (body JSON).

### 6. Richieste Assistenza (`api/support_requests`)

- **GET** `/public/index.php?page=api/support_requests`: Lista richieste.
- **GET** `/public/index.php?page=api/support_requests&id=1`: Dettagli richiesta.
- **PUT** `/public/index.php?page=api/support_requests&id=1`: Aggiorna richiesta (body JSON).

### 7. SIM/ICCID (`api/iccid`)

- **GET** `/public/index.php?page=api/iccid`: Lista SIM (con paginazione).
- **POST** `/public/index.php?page=api/iccid`: Crea nuova SIM (body JSON).

### 8. Report (`api/reports`)

- **GET** `/public/index.php?page=api/reports`: Report riepilogativo (usa `view=daily/monthly/yearly` e filtri).

### 9. Campagne Sconto (`api/discounts`)

- **GET** `/public/index.php?page=api/discounts`: Lista campagne.
- **GET** `/public/index.php?page=api/discounts&id=1`: Dettagli campagna.
- **POST** `/public/index.php?page=api/discounts`: Crea campagna (body JSON).
- **PUT** `/public/index.php?page=api/discounts&id=1&active=1`: Attiva/disattiva campagna.

### 10. Import PDA (`api/pda_import`)

- **POST** `/public/index.php?page=api/pda_import`: Upload file PDA (multipart/form-data con `pda_file`).

### 11. Autenticazione (`api/auth`)

- **POST** `/public/index.php?page=api/auth`: Login (body JSON con username/password).

## Implementazione nel Tuo Gestionale

1. **Ottieni Token**: Chiama POST /api/auth per login e salva il token.

2. **Configura la Connessione**: Usa l'URL base e header Authorization in ogni richiesta.

3. **Sincronizzazione Dati**: Chiama gli endpoint per sincronizzare (es. GET clienti, POST per creare).

4. **Gestione Errori**: Controlla `success` e gestisci errori.

5. **Esempio Completo in PHP**:

```php
<?php
use GuzzleHttp\Client;

$baseUrl = 'https://tuosito.com/public/index.php';

// Login
$client = new Client();
$response = $client->post($baseUrl . '?page=api/auth', [
    'json' => ['username' => 'tuo_user', 'password' => 'tua_pass']
]);
$data = json_decode($response->getBody(), true);
$token = $data['token'];

// Ora usa token
$clientAuth = new Client([
    'headers' => ['Authorization' => 'Bearer ' . $token]
]);

// Esempio: lista clienti
$customers = $clientAuth->get($baseUrl . '?page=api/customers');
echo $customers->getBody();
```

6. **Sicurezza**: Usa HTTPS. Non salvare password in chiaro. Limita accessi API.

## Note Finali

Questa API è basata su sessioni web. Per integrazioni più robuste, considera aggiungere autenticazione token-based. Contatta lo sviluppatore per estensioni.</content>
<parameter name="filePath">/Users/carminecavaliere/Desktop/staging-express/API_Integration_Guide.md