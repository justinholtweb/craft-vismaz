---
title: Konfiguration
slug: configuration
order: 20
summary: Verifikatlägen, svenska momsregler, bokföringskonton och koppling av betalsätt.
---

## Verifikatläge

**Fakturaläge** skapar en `CustomerInvoice` per order. **Verifikatläge** slår ihop ordrar till ett
samlingsverifikat per period — dagligen, veckovis eller månadsvis — med rader grupperade per konto.

Båda går genom samma byggare, så momsbedömningen, avrundningen och kontona avgörs likadant oavsett
läge. Det enda som skiljer är formen på det som når Visma.

## Kunder

En Visma-faktura behöver en kund. Två varianter stöds:

- **En Visma-kund per köpare.** Vad en B2B-butik vill ha — det är så Vismas egna kontoutdrag och
  betalningspåminnelser fungerar.
- **En enda gemensam webbshopskund.** Ange ett kundnummer så återanvänder Vismaz det och skapar det
  en gång om det inte finns. För en konsumentbutik är det nästan alltid rätt svar: ett kundregister
  med fyrtiotusen engångsköpare är inget kundregister, det är ett prestandaproblem med en
  dataskyddsrisk på köpet.

## Artiklar

Valfritt. Slår du på artikelsynk får du Vismas egen försäljningsstatistik per artikel; låter du bli
använder fakturorna fritextrader, vilket är precis lika korrekt bokföring och betydligt vänligare
mot ett stort sortiment. Artiklar matchas på artikelnummer innan något skapas, så en butik som redan
har sina artiklar i Visma får inget dubblettregister.

## Fälthandtag på adresser

Två inställningar pekar ut egna fält på dina Craft-adresser:

- **Organisationsnummer** — skrivs till Visma-kunden.
- **Momsregistreringsnummer** — EU-momsnumret. **Utan det kan omvänd betalningsskyldighet aldrig
  tillämpas**, eftersom Vismaz inte har någonstans att läsa numret ifrån.

## Svensk moms

Bedömningen, i tur och ordning, för en säljare i Sverige:

1. **Destinationen är Sverige** → inrikes moms med den sats Commerce tog ut.
2. **Ett annat EU-land, köparen angav momsnummer** → omvänd betalningsskyldighet. Nollbeskattad,
   bokförd mot det momsfria EU-kontot, och fakturan förses med den text som krävs.
3. **Ett annat EU-land, inget momsnummer, OSS påslaget** → moms med destinationslandets sats, följd
   per land inför kvartalsredovisningen.
4. **Utanför EU** → export, nollbeskattad.
5. **Annars** → inrikes.

Den sista raden är hela funktionens hållning: **vid tveksamhet tas momsen ut.** Att beskatta en
försäljning som inte skulle beskattas går att rätta. Att låta bli att beskatta en som skulle det gör
det inte.

### VIES-kontroll

Med kontrollen påslagen stäms ett momsnummer av mot EU:s VIES-tjänst innan en försäljning
nollbeskattas. VIES är gratis och kräver ingen nyckel — och enskilda medlemsstater tar ner sina
register utan förvarning.

**Ett nummer som VIES inte kan bekräfta lämnar försäljningen momsbelagd**, oavsett om numret är fel
eller om VIES inte går att nå. Att nollbeskatta på grund av en timeout är handlarens ansvar, inte
köparens. Svar cachas: trettio dagar för ett giltigt nummer, ett dygn för ett ogiltigt, och en
timeout cachas aldrig — den säger ingenting om numret.

### Öresavrundning

Med avrundning påslagen går fakturasumman jämnt upp i hela kronor och differensen bokförs på
avrundningskontot som en egen rad — inte insmugen i sista artikelns pris, där den i stället tyst
skulle förstöra det kontot.

## Bokföringskonton

De här spelar roll för **verifikatläget och SIE-filer**. Fakturaläget tar sina konton från Vismas
egen artikelkontering.

| Inställning | BAS-standard | |
|---|---|---|
| Försäljning 25 / 12 / 6 / 0 % | `3001` `3002` `3003` `3004` | Försäljning varor inom Sverige |
| Utgående moms 25 / 12 / 6 % | `2610` `2620` `2630` | |
| Varor till annat EU-land, momsfri | `3108` | |
| Varor utanför EU | `3105` | |
| Tjänster till annat EU-land | `3308` | |
| Tjänster utanför EU | `3305` | |
| Fakturerade frakter | `3520` | |
| Lämnade rabatter | `3730` | |
| Öresavrundning | `3740` | Öres- och kronutjämning |
| Kundfordringar | `1510` | |
| Standardkonto för inbetalning | `1580` | Fordringar för kontokort och kuponger |
| Betalningsavgifter | `6570` | Bankkostnader |

Två par här är tvärtom mot vad man gissar, och båda är värda att stämma av mot din egen kontoplan
innan du litar på dem:

- **`3305` är tjänster sålda *utanför* EU; `3308` är tjänster *till* ett annat EU-land.**
- **`3520` är fakturerad *frakt*; `3540` är faktureringsavgiften.**

Notera också att konton `2614`/`2624`/`2634` för omvänd betalningsskyldighet gäller *inköp* under
omvänd betalningsskyldighet. En **försäljning** med omvänd betalningsskyldighet har ingen utgående
moms alls, vilket är därför Vismaz inte bokför någon.

## Betalsätt

Det här är inställningen som avgör om bokföringen stämmer.

Klarna, Swish, kort och faktura redovisas inte mot samma konto, och betalförmedlarens avgift är en
kostnad i sig snarare än en intäktsminskning. Koppla varje Commerce-gateway till:

- kontot pengarna faktiskt landar på,
- kontot avgiften bokförs på,
- avgiften som procent och/eller fast belopp.

En gateway utan koppling faller tillbaka på standardkontot och bokför ingen avgift.

## Logg

Varje anrop till Visma loggas, med anropsinnehåll om du vill. Uppgifter, tokens och hemligheter
rensas bort innan något sparas, så en skärmdump av loggen till supporten läcker inga uppgifter.
Ange hur många dagar loggen ska sparas, eller `0` för att spara allt.
