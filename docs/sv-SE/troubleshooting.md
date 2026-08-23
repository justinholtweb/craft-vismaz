---
title: Felsökning
slug: troubleshooting
order: 40
summary: Vad de vanliga felen faktiskt betyder, och hur du åtgärdar dem.
---

**Anslutningsloggen** under **Vismaz → Log** sparar varje anrop, med innehåll. När något är fel är
den första stället att titta, och oftast det sista.

## "Visma refused the connection"

Nästan alltid återanropsadressen. Den måste stämma **exakt** med den som är registrerad på
Visma-appen — protokoll, värdnamn, port och sökväg. En lokal webbplats på en udda port är den
vanligaste boven.

## Anslutningen slutar fungera efter en timme

Någon refresh-token utfärdades aldrig. Vismaz begär omfånget `offline_access`, som är det som får
Visma att utfärda en över huvud taget; godkändes appen innan det omfånget begärdes saknar
medgivandet det. **Koppla från och anslut på nytt.**

## "Vismaz is not connected to Visma"

Antingen finns det verkligen ingen anslutning för den aktuella miljön, eller så gick den sparade
token inte att dekryptera — vilket händer om Crafts säkerhetsnyckel har bytts. Anslut på nytt.

Kom ihåg att sandbox och produktion har **separata** anslutningar. Att byta miljö tappar inte den
andra, men det innebär att du tittar på en annan anslutning.

## Fel företag

Vismaz ber Visma fråga efter företag vid varje anslutning, just för att det ska synas. Är företaget
som visas i inställningarna inte det du vill ha — koppla från och anslut på nytt.

## "Rate limited by Visma"

Visma tillåter 600 anrop per minut per klient och slutpunkt. Vismaz backar av och försöker igen, så
det är en fördröjning snarare än ett fel. Händer det hela tiden: stäng av artikel- eller kundsynk —
det är de anropen som växer med ordervolymen.

## Ett verifikat är "mismatched"

Visma accepterade det, men summan det bokförde skiljer sig från summan som skickades. Öppna
verifikatet för att se båda beloppen.

De vanliga orsakerna är att Visma tillämpar sin egen fakturaavrundning, eller att ett konto är
kopplat till något som beter sig annorlunda än väntat. Verifikatet **finns** i Visma — Vismaz
erbjuder inget nytt försök, eftersom det skulle bokföra det två gånger.

## "The voucher does not balance"

Upptäckt innan sändning, med differensen namngiven. Kör torrkörningen för att se verifikatet:

```sh
php craft vismaz/sync/voucher --from=… --to=… --dryRun
```

Den vanliga orsaken är att en betalsättsavgift har kopplats till ett konto som också används för
inbetalningen, så att avgiften och inbetalningen tar ut varandra.

## En EU-försäljning beskattades trots att den skulle haft omvänd betalningsskyldighet

Kontrollera, i den här ordningen:

1. Är ett **fälthandtag för momsnummer** angivet? Utan det har Vismaz ingenting att läsa.
2. Innehåller adressen faktiskt ett tolkbart EU-momsnummer? Text som `n/a` avvisas med flit, så att
   en kund inte kan nollbeskatta sin egen order genom att skriva något i rutan.
3. Öppna orderpanelen och läs **motiveringen**. Den säger vilket av ovanstående som gällde —
   inklusive "VIES could not be reached, so the sale stays taxed."

Det sista är avsiktligt, inte ett fel. Se [Konfiguration](configuration#vies-kontroll).

## "EU sale … with no VAT charged and no zero-rating rule that applies"

Commerce tog inte ut någon moms och ingen regel nollbeskattade försäljningen. Det är en lucka i
momsinställningarna, inte ett beslut Vismaz fattade, så den säger det i stället för att tyst bokföra
en nollbeskattad försäljning. Kontrollera momsreglerna i Commerce för det landet.

## Ordrar plockas inte upp

- Är ordern **slutförd**? Kundvagnar synkas aldrig.
- Finns ett **orderstatusfilter** som utesluter dem?
- Har de redan skickats? En order som redan omfattas av ett verifikat utesluts ur listan över
  osynkade — det är det som hindrar att den bokförs två gånger.

## SIE-filen läses in med förvanskade kontonamn

En SIE-fil är **CP437**, inte UTF-8, och Vismaz skriver den så. Kommer Å, Ä och Ö in förvanskade har
filen sparats om av något däremellan — en editor, en e-postklient, en förhandsvisning i en molntjänst.
Skicka filen som den genererades.

## Ingenting alls syns i loggen

Loggning av anropsinnehåll går att stänga av i inställningarna, men poster skrivs ändå. Finns det
inga poster alls görs inga anrop: kontrollera att automatisk sändning är på, eller kör ett
konsolkommando och se vad som dyker upp.
