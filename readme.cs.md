# Drago Code Order

[English](readme.md) | Čeština

Kontroluje a opravuje konvence pořadí v kódu aplikací na Nette. Balíček obsahuje dva úzce zaměřené příkazy: jeden pro metody ovládacích prvků Nette Forms volané v řetězci a jeden pro členy tříd v PHP, včetně metod životního cyklu presenterů v Nette.

Oba příkazy zachovávají těla metod i formátování. Přesouvají jen podporované deklarace nebo volání a u položek se stejnou prioritou zachovají původní pořadí. Nejednoznačný kód nechají beze změny. Před zápisem souboru se ověří, že výsledek je platné PHP se stejnými tokeny v jiném pořadí.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://github.com/drago-ex/code-order/blob/main/license)
[![PHP version](https://badge.fury.io/ph/drago-ex%2Fcode-order.svg)](https://badge.fury.io/ph/drago-ex%2Fcode-order)
[![Tests](https://github.com/drago-ex/code-order/actions/workflows/tests.yml/badge.svg)](https://github.com/drago-ex/code-order/actions/workflows/tests.yml)
[![Coding Style](https://github.com/drago-ex/code-order/actions/workflows/coding-style.yml/badge.svg)](https://github.com/drago-ex/code-order/actions/workflows/coding-style.yml)

## Požadavky

- PHP >= 8.3, < 9
- Composer 2

## Instalace

Nainstalujte globálně, abyste mohli oba příkazy používat v jakémkoli projektu, jako jiné nástroje pro Composer:

```bash
composer global require drago-ex/code-order
```

Composer zpřístupní příkazy `form-order` a `class-order` ve svém globálním adresáři `bin`. Pokud tento adresář ještě není v `PATH`, zjistěte ho příkazem `composer global config bin-dir --absolute` a přidejte ho do `PATH`. Případně nainstalujte Code Order jako vývojovou závislost jednoho projektu příkazem `composer require --dev drago-ex/code-order`.

## Pořadí metod ovládacích prvků formuláře

Kontrola souborů PHP bez jejich změny. `-d` vybírá soubor nebo adresář; bez něj se prohledá aktuální adresář:

```bash
form-order -d app
```

Použití nastaveného pořadí:

```bash
form-order -d app --fix
```

Volba `-f` je zkratka pro `--fix`. Z globálního adresáře `bin` Composeru ve Windows lze příkaz spustit i přímo přes PHP:

```powershell
php .\form-order -d C:\path\to\project\app -f
```

Příkaz pro formuláře načte `form-order.neon` z aktuálního pracovního adresáře, pokud existuje. Jiný soubor předejte přes `--config`:

```bash
vendor/bin/form-order --config form-order.neon app
vendor/bin/form-order --config=form-order.neon --fix app
```

Ve výchozím stavu jsou zapnuté jen standardní továrny ovládacích prvků Nette Forms a jejich metody. Vlastní ovládací prvky a metody projektu lze přidat ve `form-order.neon`:

```neon
form-order:
	custom:
		controls:
			- addDateRange
		methods:
			setAutocomplete: presentation
			setPlaceholder: presentation
			setReadOnly: presentation
```

Dostupné skupiny metod jsou `presentation`, `choices`, `state`, `defaults`, `input`, `required` a `validation`. Pokud vlastní metoda potřebuje konkrétní prioritu, lze místo názvu skupiny použít celé číslo; nižší čísla jdou dřív. Volání se stejnou prioritou si zachovají pořadí ze zdrojového kódu.

Pravidlo pořadí nepřidává ani neodebírá metody ovládacích prvků ani validační pravidla. Pokud řetězec obsahuje nepodporovanou metodu, nástroj ho nechá beze změny. Volání za `addCondition()` nebo `addConditionOn()` se nikdy nepřesouvají a `addFilter()` sdílí prioritu s `addRule()`, protože filtry se provádějí v pořadí spolu s pravidly. Nástroj pracuje jen se syntaxí a neověřuje, že metoda `addText()` nebo jiná nastavená továrna ovládacího prvku opravdu patří k formuláři Nette. Ukázková konfigurace pro Drago Form je v `examples/drago-project.neon`.

## Pořadí členů třídy a metod presenteru

Kontrola souborů PHP:

```bash
class-order -d app
```

Použití nastaveného pořadí:

```bash
class-order -d app --fix
```

Obecné pravidlo dává konstanty před vlastnosti, vlastnosti seskupí podle viditelnosti (`public`, `protected`, `private`), konstruktor dá před ostatní metody a zbylé metody seskupí podle viditelnosti. Členové se stejnou prioritou si zachovají vzájemné pořadí. Třída zůstane beze změny, pokud komentář sdílí řádek se členem, se složenou závorkou třídy nebo s příkazem `use`, nebo pokud by přesun konstruktoru s promoted public vlastnostmi změnil pořadí deklarace veřejných vlastností (viditelné v `json_encode()`).

U tříd, které dědí z `Nette\Application\UI\Presenter` přímo nebo přes základní třídu, jejíž název končí na `Presenter`, se rozpoznané metody za konstruktorem řadí podle životního cyklu: `inject*()`, `startup()`, `action*()`, `handle*()`, `beforeRender()`, `render*()`, `afterRender()` a `shutdown()`. Jde o konvenci pořadí ve zdrojovém kódu podle chování za běhu; Nette ji nevyžaduje.

## Návratové kódy

Oba příkazy skončí s kódem `1`, když kontrola najde kód k přeuspořádání, `0`, když nejsou potřeba žádné změny (nebo `--fix` uspěje), a `2` při chybách parsování, čtení, zápisu, konfigurace nebo cesty.

V interaktivním terminálu je `NEEDS FIX` žlutě, úspěšné opravy a čisté výsledky zeleně a chyby červeně. Barvy se vynechají, když je výstup přesměrovaný, když je nastavené `NO_COLOR` nebo když terminál hlásí `TERM=dumb`.

Při rekurzivním procházení se přeskakují adresáře `.git`, `vendor`, `node_modules`, `temp` a `cache`. Přímo zadaný soubor PHP se zpracuje bez ohledu na nadřazený adresář.
