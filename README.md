# po_linkedproduct_features

Moduł łączy produkty w grupy na podstawie:

- Prefiksu SKU (`product.reference LIKE 'PREFIX%'`).
- Wybranych cech (`feature_product` / `feature_value`).

## Nowy panel Back Office: „Powiązania produktów”

Po instalacji/aktualizacji modułu pojawia się nowa pozycja w menu modułów: **Powiązania produktów**. Z poziomu strony możesz:

- tworzyć reguły/grupy dla prefiksu SKU i 1–3 cech,
- podejrzeć dopasowanie przed zapisaniem (dry-run),
- przeliczać (rebuild) grupy,
- usuwać grupy oraz powiązania,
- przeglądać produkty należące do grupy z paginacją i filtrami.

### Widok listy grup

Kolumny:

- ID,
- prefiks SKU,
- cechy (do 3),
- liczba produktów,
- data aktualizacji.

Dostępne filtry: prefiks SKU, cecha, wartość cechy (ID), SKU produktu, ID produktu.

### Widok szczegółów grupy

- lista produktów w grupie (ID, nazwa, SKU, aktywny),
- filtr po SKU lub ID produktu,
- akcja „Przelicz grupę”,
- opcjonalne usuwanie pojedynczego produktu z grupy (bez kasowania produktu).

## Zgodność i przechowywanie danych

- Reguły/grupy są przechowywane w tabeli `po_link_group`.
- Powiązania produktów nadal opierają się o `po_link_product_family` oraz `po_link_index`.
- Dotychczasowa konfiguracja per produkt pozostaje kompatybilna.

## Upgrade / instalacja

Podczas aktualizacji do wersji `1.1.0`:

- tworzona jest tabela `po_link_group`,
- istniejące grupy są odtwarzane na podstawie wpisów w `po_link_product_family`,
- dodawany jest nowy tab w Back Office.

## Skrypt developerski (dry-run)

Skrypt uruchamiaj z katalogu modułu (po zainstalowaniu modułu w PrestaShop):

```bash
php modules/po_linkedproduct_features/scripts/linkedproduct_features_dry_run.php --prefix=SM-PL1 --features=1,2
```

Wynik pokaże liczbę dopasowanych produktów oraz przykładowe pozycje.
