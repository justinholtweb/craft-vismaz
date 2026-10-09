---
title: Användning
slug: usage
order: 30
summary: Skicka ordrar, registrera betalningar, köra samlingsverifikat, krediteringar, OSS-rapporten och SIE-export.
---

## Förhandsgranska en order

Varje slutförd order får en **Vismaz**-panel i Commerce egen ordervy, som visar hur ordern
beskattas, *varför* den beskattas så, och vilka konton den bokförs på.

**Preview** bygger verifikatet utan att skicka det. Den kör samma byggare som den skarpa
sändningen, med uppslag mot Visma avstängda, så den skapar ingenting i Visma. Det du ser är det som
skulle bokföras — inte en ungefärlig bild av det.

## Skicka

**Send to Visma** bokför nu. I verifikatläge dras en enskild order i stället in i periodens
verifikat, eftersom det är där den hör hemma.

Slår du på **Send automatically when an order completes** lägger Vismaz ett jobb i kön när ordern
slutförs. Det är ett köjobb och inte ett direktanrop, medvetet: ett driftstopp hos Visma, en utgången
token eller en trög VIES-slagning får aldrig kunna hindra en kund från att betala.

## Betalningar

I fakturaläget **registreras varje lyckad debitering (capture eller purchase) som en betalning mot
orderns faktura i Visma**. Fakturan stängs då i kundreskontran, och Vismas påminnelser jagar aldrig
en kund som redan betalat i kassan.

- **En Commerce-transaktion blir en betalning i Visma.** En delbetalning registreras som
  delbetalning; den som gör ordern fullt betald registreras som slutbetalning.
- **Belopp och valuta** är transaktionens. **Datumet** är dagen pengarna drogs, i webbplatsens
  tidszon (Visma läser det i företagets tidszon och tar inte emot framtida datum).
- Betalningen går till det **bankkonto i Visma som är kopplat till gatewayen** under
  [Betalsätt](configuration#betalsatt), eller till standardkontot.
- En betalning som kommer **innan fakturan finns** — det vanliga, eftersom betalningen är det som
  slutför ordern — visas som *väntar* och registreras så snart fakturan är i Visma.

Registreringen köas när Commerce sparar transaktionen, aldrig direkt i kassan. Svarar Visma inte,
eller med 5xx, försöker kön igen upp till fem gånger; ett nej (4xx, inget bankkonto kopplat, en
annan valuta än fakturans) sparas med orsaken och lämnas åt dig.

Orderpanelen listar varje betalning med status och referensen i Visma, och **Registrera
betalningar** kör om alla betalningar på ordern som ännu inte finns i Visma. En redan registrerad
betalning hoppas över — två tryck skickar ingenting två gånger. Misslyckade och väntande
betalningar kan också köras om från konsolen:

```sh
php craft vismaz/sync/payments
```

Varje försök syns i **Vismaz → Logg**, mot ordern och fakturan.

Före varje betalning frågar Vismaz Visma hur mycket som är obetalt på fakturan. En betalning som
skulle överbetala den med mer än 50 öre **skickas inte** — skyddet mot att registrera samma
betalning två gånger när ett tidigare försök nådde Visma men svaret försvann. Upp till 50 öre
tillåts, eftersom en faktura avrundad till hela kronor kan vara så mycket lägre än det kunden betalade.

Samlingsverifikatläget registrerar inga betalningar: verifikatet bokför redan varje order mot
gatewayens avräkningskonto.

## Samlingsverifikat

Från **Vismaz → Documents**, välj ett datumintervall och tryck **Send summary voucher**. Eller från
konsolen, där du kan repetera först:

```sh
php craft vismaz/sync/voucher --from=2026-08-01 --to=2026-08-31 --dryRun
```

Torrkörningen skriver ut verifikatet med debet och kredit och talar om huruvida det balanserar:

```
Webshop sales 2026-08-01 – 2026-08-31 (412 orders)

  Account                                            Debit         Credit
  1580     Settlement                            512 340,90
  6570     Payment fees                            7 685,10
  1580     Payment fees                                          7 685,10
  3001     Försäljning varor inom Sverige, 25 %                409 872,00
  2610     Utgående moms, 25 %                                 102 468,00
  3740     Öresavrundning                                            0,90

  Balances.
```

Ett verifikat som inte balanserar **avvisas i stället för att skickas**, med differensen namngiven.
Visma avvisar det också, men dess felmeddelande säger inte vilken rad som är fel.

## Krediteringar

**Credit refunds** skapar en kreditfaktura på det belopp som faktiskt har återbetalats. Den nycklas
på återbetalningstransaktionerna snarare än på ordern, så en andra delåterbetalning ger en andra
kreditfaktura medan ett nytt försök på den första inte gör det.

## Vad som händer efter en sändning

Vismaz sparar fakturanumret eller verifikationsnumret Visma tilldelar, och **stämmer sedan av
summan Visma bokförde mot summan som skickades**. Skiljer de sig — Vismas egen fakturaavrundning,
en koppling som är subtilt fel — markeras verifikatet **mismatched** i stället för att rapporteras
som skickat.

Det spelar roll eftersom en felaktig koppling inte kastar något fel. Den bokför fel belopp, tyst,
och ingen märker det förrän någon stämmer av banken månader senare.

Ett verifikat med avvikelse ligger redan i Visma, så Vismaz erbjuder inget nytt försök — det skulle
bokföra det en gång till. Rätta det för hand.

## Göra om misslyckade sändningar

```sh
php craft vismaz/sync/retry
```

Misslyckade verifikat **byggs om från ordern som den ser ut nu**, inte återuppspelade från det
sparade anropet. En order som rättats efter ett fel skickas i rättat skick.

## OSS-rapporten

**Vismaz → OSS report** delar upp ett kvartal per destinationsland och momssats — den form
deklarationen frågar efter. Den läser samma momsbedömningar som bokföringen byggdes på, så rapporten
och bokföringen kan inte säga emot varandra.

## SIE 4-export

För handlare som kör **Visma Administration** som skrivbordsprogram, vilket inte har något publikt
API. Samma verifikat som annars hade bokförts skrivs till en `.se`-fil som redovisningskonsulten
läser in för hand.

```sh
php craft vismaz/sie/export --from=2026-08-01 --to=2026-08-31 --path=./augusti.se
```

Eller hämta den från **Vismaz → Documents**. Ordrar som redan skickats till Visma **utesluts som
standard** — att ta med dem också är hur en handlare bokför en månad två gånger.

## Konsolreferens

```sh
php craft vismaz/sync/orders --dryRun --from=2026-08-01 --to=2026-08-31
php craft vismaz/sync/orders --from=2026-08-01 --to=2026-08-31
php craft vismaz/sync/voucher --from=2026-08-01 --to=2026-08-31 [--dryRun]
php craft vismaz/sync/retry
php craft vismaz/sync/payments [--limit=100]
php craft vismaz/sync/bank-accounts
php craft vismaz/sie/export --from=… --to=… [--path=…] [--includeSynced]
php craft vismaz/auth/status
php craft vismaz/auth/refresh
php craft vismaz/log/prune [--days=30]
```

En torrkörning kräver ingen Visma-anslutning alls. Det är själva poängen med den.

## Twig

```twig
{% if craft.vismaz.isConnected() %}
  {% set treatment = craft.vismaz.treatment(order) %}
  {{ treatment.getLabel() }} — {{ treatment.reason }}

  {% for document in craft.vismaz.documentsForOrder(order) %}
    {{ document.type }}: {{ document.vismaNumber ?? 'pending' }}
  {% endfor %}
{% endif %}
```
