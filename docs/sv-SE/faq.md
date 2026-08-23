---
title: Vanliga frågor
slug: faq
order: 50
summary: Vanliga frågor om att få in Craft Commerce-ordrar i Visma.
---

## Vilken Visma-produkt gäller det?

**Visma eAccounting** — i Sverige sålt som *Bokföring & Fakturering*, och före namnbytet 2025 som
*Visma eEkonomi*. Namnbytet ändrade namnet och varumärket, inte API:et.

Det gäller **inte** Visma.net ERP eller Visma Business. Det är andra produkter med andra API:er och
nästan ingen överlappning med Craft Commerce-marknaden.

## Jag kör Visma Administration som skrivbordsprogram. Har jag någon nytta av det här?

Ja, via SIE-exporten. Visma Administration har inget publikt API, så ingenting kan skicka till det —
men det läser in SIE precis som alla andra svenska bokföringsprogram. Vismaz skriver samma verifikat
som annars hade bokförts, till en `.se`-fil som din redovisningskonsult importerar.

## Vad kostar det?

79 USD, med en förnyelse på 59 USD/år för fortsatta uppdateringar och support. Ett pris, allt
påslaget — det finns ingen nivå med avstängda funktioner. Förnyelsen är frivillig: pluginet
fortsätter fungera när den löper ut, du slutar bara få uppdateringar.

## Fakturor eller samlingsverifikat — vad ska jag välja?

Säljer du B2B, i låg volym, eller behöver dina kunder kontoutdrag och påminnelser från Visma:
**fakturor**.

Driver du en konsumentbutik med riktig volym: **verifikat**. Niohundra ordrar om dagen ska bli ett
verifikat, inte niohundra fakturor i en kundreskontra ingen någonsin läser. Det är oftast det här
din redovisningskonsult kommer att be om.

## Kan den bokföra samma order två gånger?

Nej. Varje verifikat har en idempotensnyckel med ett unikt databasindex bakom sig. Kön kan göra ett
nytt försök, du kan trycka två gånger och konsolen kan köra — allt i samma ögonblick — och det finns
ändå bara ett verifikat när allt är klart. En order kan heller inte hamna i två olika
samlingsverifikat.

## Är förhandsgranskningen verkligen det som skickas?

Ja, bokstavligen. Förhandsgranskningen, torrkörningen i konsolen, den skarpa sändningen och
SIE-skrivaren kör alla samma byggare och läser samma verifikatobjekt. En förhandsgranskning byggd av
annan kod än sändningen vore en förhandsgranskning du inte kunde lita på, vilket vore sämre än
ingen alls.

## Kan ett problem hos Visma stoppa min kassa?

Nej. När en order slutförs läggs ett **jobb i kön**; ingenting anropar Visma under själva förfrågan.
Ett driftstopp, en utgången token eller en trög VIES-slagning kan inte nå kunden som betalar.

## Vad händer om VIES ligger nere när ett EU-företag beställer?

Försäljningen förblir momsbelagd. Vismaz nollbeskattar inte på grund av en timeout — går numret inte
att bekräfta är det att ta ut momsen som är det misstag som går att rätta, och att låta bli är det
inte. Orderpanelen säger precis det, så du kan korrigera för hand om du vet att numret stämmer.

## Hanterar den öresavrundning?

Ja, som en riktig bokföringspost på 3740 snarare än ett avrundningsfel insmuget i sista radens pris.
Den går att stänga av om ditt Visma-företag hanterar det i stället.

## Hanterar den OSS?

Ja. EU-konsumentförsäljning beskattas med destinationslandets sats, och OSS-rapporten delar upp ett
kvartal per land och sats inför deklarationen. Den läser samma momsbedömningar som bokföringen
byggdes på, så rapporten kan inte säga emot bokföringen.

## Hur är det med ROT och RUT?

Inte i den här versionen. De kräver fält på fakturan som bara är meningsfulla för tjänster sålda till
hushåll, och en webbshop som säljer varor kommer aldrig att använda dem.

## Ändrar den mina befintliga uppgifter i Visma?

Den skapar fakturor, verifikat, kunder och artiklar. Kunder och artiklar **matchas innan något
skapas**, på kundnummer och artikelnummer, så en butik som redan har sitt register i Visma får inget
dubblettregister. Både artikel- och kundsynk går att stänga av helt.

## Kan jag testa innan den rör riktig bokföring?

Ja, och det bör du. Vismas testmiljö är kostnadsfri och du registrerar dig själv. Anslut den, kör en
förhandsgranskning eller en torrkörning i konsolen mot dina riktiga ordrar och läs anropet. En
torrkörning kräver ingen anslutning alls och skapar ingenting.

## Vad händer om summan Visma bokför skiljer sig från den jag skickade?

Vismaz stämmer av dem och markerar verifikatet **mismatched** i stället för att rapportera att det
gick bra. Det är det felet som är värt att fånga: en subtilt felaktig koppling kastar inget fel, den
bokför bara fel belopp.

## Vilka versioner stöds?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.
