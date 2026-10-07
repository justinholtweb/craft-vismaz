---
title: Installation
slug: installation
order: 10
summary: Krav, installation och hur du kopplar Vismaz till ditt Visma-företag.
---

## Krav

- Craft CMS 5.3 eller senare
- Craft Commerce 5.0 eller senare
- PHP 8.2 eller senare
- En Visma-utvecklarklient — ett `client_id` och ett `client_secret`. Du registrerar dig själv för
  testmiljön i Vismas utvecklarportal.

## Installera

```sh
composer require justinholtweb/craft-vismaz
php craft plugin/install vismaz
```

Eller sök upp **Vismaz** i Craft Plugin Store och installera därifrån.

## Ingenting skickas förrän du säger till

Att installera Vismaz ändrar ingenting för dina ordrar. Automatisk sändning är **avstängd** från
början, och innan du har kopplat ett Visma-företag finns det ingenting att skicka till. Du kan
installera pluginet, ansluta en testmiljö och titta på exakt vad som *skulle* bokföras innan något
alls rör riktig bokföring.

## Registrera återanropsadressen

I Vismaz inställningar visas en **återanropsadress** (redirect URI). Registrera den på din
Visma-app **exakt**, tecken för tecken — inklusive protokoll och eventuell port. Visma avvisar en
adress som inte stämmer redan innan webbläsaren når en inloggningsruta, och felet den ger säger
inte vilken del som skilde sig.

## Anslut

1. Gå till **Inställningar → Plugins → Vismaz**.
2. Välj **Sandbox** eller **Production**. Det är helt separata värdar, uppgifter och företag, och
   Vismaz håller en anslutning för var och en — att byta mellan dem tappar alltså ingen av dem.
3. Klistra in **Client ID** och **Client secret**. Båda tar emot miljövariabler, och på en riktig
   webbplats bör de vara miljövariabler.
4. Spara, gå sedan till **Vismaz → Anslutning**, tryck **Connect to Visma** och välj företag.

Anslutningsskärmen är skild från inställningarna med avsikt. Plugin-inställningar är skrivskyddade
på en produktionswebbplats, där `allowAdminChanges` är avstängt, men anslutningen är ingen
inställning: dess token lagras i databasen. En återkallad token kan alltså anslutas på nytt i
produktion, av en administratör eller av den som har behörigheten **Ansluta, testa och koppla från
Visma**. Ange klient-ID och hemlighet som miljövariabler där, eftersom inställningsskärmen inte
sparar dem.

Vismaz ber alltid Visma om att få välja företag i stället för att ta det du senast loggade in på.
En handlare med både ett rörelsedrivande bolag och ett holdingbolag under samma inloggning skulle
annars kunna koppla fel bokföring utan att något syns.

## Kontrollera innan du litar på den

Tryck **Test connection**. Den namnger företaget den nådde, vilket är den enda bekräftelse som är
värd något.

Öppna sedan en slutförd order och tryck **Preview** i Vismaz-panelen. Den kör samma byggare som den
skarpa sändningen, med uppslag mot Visma avstängda, så den skapar ingenting i Visma och visar exakt
det anrop som skulle skickas.

## Vilket läge ska du välja?

| | Fakturaläge | Verifikatläge |
|---|---|---|
| Vad Visma tar emot | En kundfaktura per order | Ett verifikat per period |
| Passar | B2B, låg volym, kunder som behöver kontoutdrag | Konsumentbutiker med riktig volym |
| Vismas påminnelser och kontoutdrag | Fungerar som vanligt | Inte tillämpligt |
| Kundreskontran | En post per köpare | Orörd |

En konsumentbutik med volym vill ha **verifikatläge**. Niohundra ordrar om dagen blir ett verifikat
med en handfull konteringar, i stället för niohundra fakturor i en kundreskontra som ingen kommer
att titta i.

## Innan du skickar något på riktig

Stäm av kontona mot handlarens egen kontoplan. Vismaz levereras med BAS-standard som stämmer för de
flesta svenska butiker, men "de flesta" är inte "din" — och ett felaktigt kontonummer smäller inte,
det bokför tyst fel tills någon stämmer av året.

Se [Konfiguration](configuration) för vad varje konto gör.
