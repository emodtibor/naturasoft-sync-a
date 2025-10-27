# Naturasoft CSV Import – DROP-IN kiegészítés

Ez a csomag a CSV termékimport funkciót adja hozzá a **már telepített és működő** Naturasoft rendeléses pluginodhoz, anélkül hogy a rendeléses végpontokat módosítaná.

## Használat
1. Csomagold ki ezt a zipet a meglévő plugin mappádba (`wp-content/plugins/<a_te_pluginod>/`).
   - Új fájl jön létre: `includes/class-naturas-products-import.php`
2. A fő plugin fájlodban (ahol a több `require_once` is van) add hozzá:
   ```php
   require_once plugin_dir_path(__FILE__) . 'includes/class-naturas-products-import.php';
   if ( class_exists('Naturasoft_Products_Import') ) {
       Naturasoft_Products_Import::init();
   }
   ```
3. Az API hívások hitelesítéséhez a `naturasoft_api_key` opciót használja (X-Naturasoft-Key).

## Új REST végpont
`POST /wp-json/naturasoft/v1/products`

- Fejléc: `X-Naturasoft-Key: <kulcs>`
- Törzs: multipart `file=@/path/to/export.csv` **vagy** `csv` szöveg
- CSV: UTF-8, `|` elválasztó, kötelező fejléc:
  `NATURASOFTID|MEGNEVEZES|TERMEKKOD|CIKKSZAM|VTSZ|MEE|SZABADKESZLET|`

## Példa export (mintafájl)
A csomagban találsz egy `sample-naturasoft-export.csv` fájlt is.

## Gyors teszt (száraz futás)
```bash
curl -X POST "https://a-te-doma.in/wp-json/naturasoft/v1/products?dry_run=true"       -H "X-Naturasoft-Key: A_TE_KULCSOD"       -H "Content-Type: multipart/form-data"       -F "file=@/path/to/sample-naturasoft-export.csv"
```
