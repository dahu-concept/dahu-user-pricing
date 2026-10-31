# Dahu User Pricing — résumé technique

Document interne. L'extension publique est en anglais ; ce fichier est le seul
du dépôt qui parle d'Annad, et il le fait parce que c'est la seule façon
d'expliquer ce qui a été porté, ce qui a été refait, et pourquoi.

---

## 1. Portage depuis la version Annad

### 1.1 Ce que la version Annad était, et pourquoi ça ne se portait pas

`dahu-pricing` 1.6.0 n'était pas une extension de tarification. C'était une
**couche d'affichage** posée sur un fork du plugin commercial Addify *Role
Based Pricing for WooCommerce* : Addify calculait les prix, `dahu-pricing` les
formatait, les libellait et réparait ses bugs. Trois de ses huit modules
instanciaient directement `AF_C_S_P_Price`.

Mesure du dépôt source : 9 fichiers, 67 Ko de PHP, aucun asset. Les cinq
fichiers JS et la feuille CSS que `class-dahu-pricing-assets.php` met en file
**n'existent pas dans le dossier** — ils étaient dans le thème ou perdus. Ce
n'est pas un obstacle : voir §1.4, ils ne devaient pas être portés.

| Module source | Dépendance | Sort |
|---|---|---|
| `role-context` | — | porté, généralisé (§1.2) |
| `body-classes` | — | porté, absorbé dans `display` |
| `settings` | — | refait (§1.2) |
| `assets` | — | refait (§1.4) |
| `role-preview` | — | porté, simplifié (§1.5) |
| `admin-order-tax-fix` | Addify | **à moitié porté** (§1.6) |
| `bundle-compat` | Addify + WC Product Bundles | abandonné (§1.7) |
| `composite` | WC Composite Products + thème Annad | abandonné (§1.7) |

**Une extension qui affiche des prix qu'elle ne sait pas calculer n'est pas
installable.** C'est la décision qui commande tout le reste : la version
publique **possède sa source de prix**. Un prix de palier est une valeur
stockée sur le produit, et `dahu_user_pricing_price` est le moyen, pour une
autre extension, d'en fournir une à la place — Addify compris, par qui veut
l'écrire.

### 1.2 Deux paliers codés en dur deviennent des paliers arbitraires

La version Annad connaissait `TIER_OR` et `TIER_ARGENT`, en constantes de
classe, avec quatre réglages de libellés nommés `label_or_client`,
`label_or_pro`, `label_argent_client`, `label_argent_pro`. Toute autre boutique
se heurtait immédiatement à la question « où j'ajoute le troisième ? », sans
réponse.

Un palier est donc une donnée : `Dahu_User_Pricing_Tiers`, créée depuis
l'écran de réglages, sans liste intégrée et sans limite.

**La clé d'un palier est permanente.** Les prix vivent dans une méta nommée
`_dahu_user_pricing_price_<clé>` ; une clé qui changerait rendrait
inaccessibles tous les prix classés sous l'ancienne, sans rien à l'écran pour
le dire. La clé est donc dérivée du libellé **une fois**, à la création, et
plus jamais. Renommer le libellé est gratuit, la clé cesse simplement de lui
ressembler — moindre mal : une clé un peu datée se lit, des prix perdus non.

Deux conséquences qu'il a fallu écrire explicitement :

- **Vider le libellé d'un palier existant ne le supprime pas.** La première
  version de `sanitize()` reconstruisait les paliers à partir des lignes
  soumises et ignorait celles sans libellé : un champ texte effacé supprimait
  donc le palier et démappait ses rôles. Un acte destructeur déclenché par un
  champ vide, exactement ce que le bouton de suppression explicite est là pour
  être à la place. Un palier existant au libellé vide garde sa clé comme nom.
- **Supprimer un palier ne supprime pas ses prix.** `Tiers::delete()` retire
  le palier des réglages et démappe ses rôles ; les métas restent. Recréer le
  palier sous le même nom les retrouve toutes. Le nombre de produits concernés
  est affiché à côté du bouton, parce que « supprimer Trade » et « supprimer
  Trade, que 418 produits ont un prix pour » ne sont pas la même décision.

### 1.3 Le coefficient HT/TTC disparaît

La version Annad avait un réglage `coefficient` (1.2 par défaut) et un
`apply_to_argent`. Ils n'étaient pas une fonctionnalité : Addify renvoyait un
prix déjà converti pour l'affichage — il lisait
`woocommerce_tax_display_shop`, un réglage d'**affichage** — et il fallait
reconvertir à la main pour retrouver du HT.

La version publique stocke le prix de palier dans la base de prix de la
boutique, celle de `_price`. La question n'est donc plus un réglage
d'affichage mais `wc_prices_include_tax()`, et `wc_get_price_excluding_tax()`
y répond avec le code même dont WooCommerce se sert pour construire une ligne
de panier. Les deux réglages sont supprimés sans remplacement.

### 1.4 Les cinq scripts de réécriture du DOM ne sont pas portés

`dahu-pricing-role-display.js`, `-composite.js`, `-toggle.js`, `-cart.js` :
quatre scripts parcouraient la page rendue, retrouvaient les montants par nom
de classe, les recalculaient et les réécrivaient. Ça fonctionne sur un seul
thème, se casse en silence dès qu'un thème change une enveloppe, affiche
brièvement le mauvais prix avant de s'exécuter, et est invisible pour tout ce
qui lit la page sans exécuter de script.

Tout est maintenant assemblé côté serveur dans
`Dahu_User_Pricing_Display::filter_price_html()`. Il reste **un** fichier JS
dans l'extension, pour l'écran de commande (§1.6), et il ne touche à aucun
montant.

### 1.5 L'aperçu de rôle devient un aperçu de palier

La version Annad devait contourner Addify, qui lisait `$current_user->roles`
directement : elle **ajoutait** le rôle prévisualisé à ce tableau pour la
durée de la requête. Ça marchait, et ça donnait au compte qui prévisualise les
capacités du rôle, en effet de bord.

Ici l'extension possède sa résolution de prix : l'aperçu force **une seule
chose**, le palier. Aucun rôle, aucune capacité. `Context::is_manager()` lit
les rôles réels sans passer par `current_user_can()`, précisément pour qu'un
aperçu ne puisse pas influencer un contrôle de droits.

**Le durcissement réel du portage** : la version Annad avertissait « ne pas
finaliser de vraie commande dans ce mode », par une bannière. Un avertissement
n'arrête pas quelqu'un de fatigué à vingt-et-une heures. La version publique
**refuse la commande** : `woocommerce_after_checkout_validation` ajoute une
erreur tant qu'un aperçu tourne, et le message nomme la sortie.

### 1.6 Le correctif de commande admin : la moitié saine

`class-dahu-pricing-admin-order-tax-fix.php` (21 Ko, le plus gros fichier de
la version Annad) existait pour réparer une double-taxation d'Addify : ce
dernier injectait un montant TTC dans `set_subtotal()`/`set_total()`, champs
que WooCommerce traite toujours comme hors taxe, et `calculate_totals()`
rajoutait la TVA par-dessus.

Le bug d'Addify ne nous concerne plus. **Le problème de fond, si.** Une ligne
ajoutée depuis la modale « Ajouter un produit » est tarifée depuis le
catalogue, par une requête dont l'utilisateur courant est le gestionnaire de
la boutique : aucun filtre de prix ne sait qui est le client de la commande.
La commande téléphonique d'un revendeur sort donc au prix public. C'est ce que
`Dahu_User_Pricing_Order` corrige, et c'est pour cette raison qu'il existe en
plus des filtres de `prices.php` : `set_subtotal()` écrit un montant **sur la
ligne**, là où aucun filtre ne peut l'atteindre.

Ce qui a été repris tel quel, parce que c'est juste :

- **Retraiter toute la commande, pas seulement les lignes ajoutées** : un
  client changé après la première ligne doit retarifer la première ligne.
- **Persister le client posté avant de tarifer.** Sur « Ajouter une
  commande », `#customer_user` n'est écrit dans le brouillon qu'au clic sur
  « Créer » ; le navigateur envoie la valeur courante avec la requête d'ajout,
  et le serveur la persiste quelques secondes plus tôt.
- **La garde sur les lignes enfants.** Une ligne portant `_bundled_by`,
  `_composite_parent` ou `_composite_item` a son prix porté par une ligne
  parente et doit rester à zéro. La retarifer depuis son propre prix produit
  facture le composant deux fois, en silence. L'exception `yes` sur
  `_bundled_item_priced_individually` est reprise aussi. La liste est devenue
  filtrable (`dahu_user_pricing_child_line_metas`) : il existe plus
  d'extensions de lot que personne ne peut énumérer, et l'extension ne dépend
  d'aucune — elle reconnaît leurs métas pour ne pas les abîmer.

Ce qui a été **refait** :

- **La décomposition HT/taxe à la main disparaît.** La version Annad lisait
  `woocommerce_tax_display_shop`, appelait `WC_Tax::calc_inclusive_tax()` ou
  `calc_exclusive_tax()`, sommait, soustrayait, et assignait `set_taxes()`
  elle-même. Nécessaire là-bas (§1.3), inutile ici. On pose le HT via
  `wc_get_price_excluding_tax()` et on laisse `$order->calculate_totals()`
  recalculer les taxes depuis l'adresse de taxation de la commande, sa classe
  de taxe et les taux composés. **Une seconde implémentation de la TVA dans
  une extension est une seconde implémentation de la TVA à maintenir juste.**
- **L'instantané/restauration des composants de composite disparaît.** Il
  existait pour annuler le passage d'Addify en priorité 100 sur les mêmes
  lignes. Sans Addify, personne ne les écrase : la garde suffit.
- **Le recalcul au changement de client n'est plus automatique.** La version
  Annad recalculait toutes les lignes dès que `#customer_user` changeait. Une
  ligne peut avoir été ajustée à la main, et un recalcul silencieux la jetait
  sans annulation possible. Un bouton apparaît à la place, et rien ne se passe
  avant qu'on le presse. Pas de `confirm()` non plus : une modale bloque tout
  ce qui est derrière, y compris ce qui pilote le navigateur.
- **`resolve_line_pricing()` ne renvoie plus les taxes.** Elle renvoie le prix
  unitaire HT et le palier qui l'a produit. `dahu-sav` n'utilisait déjà que le
  HT et faisait ses taxes lui-même ; le reste du monde a
  `WC_Order_Item::calculate_taxes()`.
- **Suspension explicite pendant la résolution.** Le prix voulu est celui du
  client de la **commande**, et l'utilisateur courant est un gestionnaire qui
  peut avoir un palier à lui. `Prices::suspend()` / `resume()` encadre la
  résolution, par compteur et non par booléen : `resume()` ne doit pas
  réactiver une tarification qu'un appelant extérieur avait suspendue.

### 1.7 Ce qui n'est pas porté du tout

- **`bundle-compat`** : instanciait `AF_C_S_P_Price` et réparait le fait
  qu'Addify ne traite pas le type de produit `bundle`. Les deux raisons
  disparaissent : la liste blanche de types d'Addify n'est plus notre
  problème, et notre filtre `woocommerce_product_get_price` s'applique à
  n'importe quel type de produit sans liste blanche.
- **`composite`** : neutralisait le total natif de WooCommerce Composite
  Products pour laisser un script recalculer, et rendait en dur
  `<ins class="MontantTotal">0,00 €</ins>` — un nom de classe du thème Annad
  et un symbole monétaire codé en dur. Procédure Annad de bout en bout.
- **Les quatre scripts d'affichage** (§1.4).

### 1.8 Le piège que le portage a dû traiter et que la source n'avait pas

**Le cache de prix des variations.** WooCommerce met en cache la fourchette de
prix d'un produit variable dans un transient indexé par un hash. Une extension
qui modifie les prix de variation par utilisateur et oublie d'ajouter le
palier à ce hash **sert les prix d'un compte à un autre depuis le cache**.
C'est le défaut le plus répandu de cette catégorie d'extensions, et il est
invisible en test : il faut deux comptes et un cache chaud pour le voir.

La version Annad ne rencontrait pas le problème parce qu'elle ne touchait pas
aux prix — Addify s'en occupait. Dès que l'extension possède sa source de prix,
le piège est le sien. `woocommerce_get_variation_prices_hash` reçoit donc le
palier, et c'est l'un des contrôles du banc.

---

## 2. Banc fonctionnel

`_bench/t-user-pricing.php` : **272 contrôles, 0 échec**, sur un vrai WordPress
7.1.3 avec un vrai WooCommerce 11.1.2 et une vraie base. Rien n'est simulé :
les produits sont des objets WooCommerce, le panier est le panier de
WooCommerce, les commandes sont des `WC_Order`, et chaque montant vient des
filtres de l'extension.

| § | Ce qui est couvert |
|---|---|
| 0–1 | mise en place, modèle de paliers, dérivation et unicité des clés |
| 2 | validation des réglages, y compris les deux pièges destructeurs (§1.2) |
| 3 | résolution du palier : visiteur, compte, gestionnaire, rôles multiples |
| 4 | prix des produits simples, vide ≠ zéro, négatif refusé, solde non empilé |
| 5 | source de prix enfichable, y compris un `null` qui annule le palier |
| 6 | variations, héritage du parent, cache de fourchette de prix |
| 7 | périmètre d'application et compteur de suspension |
| 8 | rendu du prix : libellés, `<ins>`/`<del>`, ordre, marge, fourchettes |
| 9 | bascule d'affichage, et qu'elle ne change pas le prix facturé |
| 10 | panier : tarif du palier, TVA, palier à 0, bascule sans effet |
| 11 | résolution ligne par ligne, boutique en HT **et** en TTC |
| 12 | commandes admin : retarification, lignes enfants, changement de client |
| 13 | aperçu de palier, bannière, refus de commande |
| 14–15 | enregistrement des champs produit et de variation, nonces, capacités |
| 16 | les treize défauts de la revue adverse (§6) |
| 17 | désinstallation, avec et sans purge |

**Idempotent**, vérifié par exécutions répétées : toutes les fixtures sont
créées dans la mise en place et leurs métas réécrites à zéro, parce qu'un test
qui passe au premier passage et échoue au second ressemble exactement à une
régression de l'extension.

### 2.1 Les contrôles sont éprouvés par injection

`_bench/prove-user-pricing.py` applique **32 défauts volontaires** dans
l'extension, un par un, relance le banc, et exige que l'assertion nommée tombe.
**Les 32 sont attrapés.** Un contrôle qui reste vert pendant que la faute qu'il
guette est présente n'est pas un contrôle — c'est arrivé deux fois sur ce
chantier.

Deux choses apprises en l'écrivant, et inscrites dedans :

- **L'injection sur le hash du cache de variations ne tombe pas où on croyait.**
  L'hypothèse était « le premier compte à chauffer le cache décide de ce que
  les autres voient » ; la mesure dit que la clé étant commune, c'est la
  première entrée écrite qui gagne et qu'elle est publique, donc le compte à
  palier ne voit jamais son tarif. Le défaut est bien attrapé, par une autre
  assertion que celle prévue, et le fichier le dit.
- **Un passage interrompu laissait une injection en place.** C'est arrivé : le
  processus a été tué au milieu, l'extension est restée avec sa vérification de
  capacité désactivée — et le banc est resté **vert** par-dessus un fichier
  mutilé, parce que le code injecté se trouvait équivalent par accident. Le
  script prend donc une copie complète de l'extension avant de commencer, la
  restaure sur tous les chemins de sortie y compris un signal, et la restaure
  aussi au démarrage s'il trouve une copie d'un passage précédent.

### 2.2 Ce que le banc ne peut pas voir

Le WooCommerce du banc est une copie de source **sans assets construits** :
l'écran d'édition produit s'affiche sans son JavaScript, donc ses onglets ne
fonctionnent pas et les champs de prix par palier n'y sont pas *visibles*. Ils
sont bien émis — vérifié dans le DOM, balisage et feuille chargée — et leur
enregistrement est couvert par les §14 et §15, mais l'apparence de cet écran-là
n'a pas été regardée. C'est le seul écran de l'extension dans ce cas.

---

## 3. Ce qui reste à faire

- **Vérifications en boutique réelle.** Le banc tourne sur WooCommerce 11.1
  avec une base SQLite et des taux de taxe simples. Quatre choses demandent une
  vraie boutique :
  1. un produit variable à quarante variations, cache chaud, **deux requêtes
     HTTP distinctes** et deux comptes de paliers différents — la fuite d'un
     compte vers l'autre ne se reproduit pas dans un seul processus (§2.1) ;
  2. une boutique en prix TTC stockés avec taux composés ;
  3. une commande admin contenant un lot WooCommerce Product Bundles ;
  4. l'écran d'édition produit sur un WooCommerce avec assets construits (§2.2).
- **Tri et filtrage par prix.** `wc_product_meta_lookup.min_price` /
  `max_price` ne sont pas filtrés : un client à palier voit le catalogue
  *trié* aux prix publics, et un filtre de prix peut exclure un produit que son
  tarif aurait fait entrer. C'est la limite connue de toutes les extensions de
  cette catégorie — la corriger demande de maintenir une table de recherche par
  palier. À décider, pas à bricoler.
- **Produit variable en solde.** Seul `price` est filtré, donc
  `WC_Product_Variable::is_on_sale()` (qui compare `sale_price` et `price`) ne
  dit plus « en solde » pour un compte à palier. C'est cohérent avec la
  décision de ne pas empiler solde et palier, mais ça mérite d'être écrit dans
  la fiche.
- **Traduction française.** 0 chaîne traduite. Voir le chantier commun des neuf
  extensions.
- **Captures d'écran** `screenshot-1.png`… pour la fiche wordpress.org.
- **Disponibilité du slug** `dahu-user-pricing` sur wordpress.org, à vérifier
  depuis une machine qui atteint le site.

---

## 4. Points d'architecture à connaître

### 4.1 Où la tarification s'applique, et où elle est coupée

`Prices::is_active()` répond à une seule question : « ce prix est-il calculé
pour le visiteur courant ? »

- **Front** : oui.
- **Écrans wp-admin** : non. Les prix affichés dans l'admin sont ceux de la
  boutique, et un gestionnaire qui a aussi un palier — les comptes de test en
  ont presque toujours un — ne doit pas voir son propre tarif en éditant un
  catalogue.
- **admin-ajax** : oui, et c'est volontaire. L'ajout au panier classique et le
  rafraîchissement des fragments de panier passent par là et font partie de la
  session d'un visiteur. L'AJAX de l'écran de commande est traité par
  **suspension** (§1.6), ce qui est précis au lieu d'être approximatif.

### 4.2 Le palier est résolu une fois par requête

`Context::get_tier()` mémoïse, parce qu'une page de boutique tarife deux cents
produits et parcourrait sinon la cartographie des rôles deux cents fois. Trois
états, et le troisième est la raison pour laquelle ce n'est pas un simple test
à `null` : non résolu, résolu à « aucun palier », résolu à un palier.
`flush()` existe pour ce qui change la réponse — un aperçu qui démarre ou
s'arrête.

### 4.3 `get_tier()` et `get_tier_for_user()` ne sont pas interchangeables

`get_tier()` honore l'aperçu. `get_tier_for_user()` l'ignore. Un aperçu est
une propriété de **qui regarde l'écran**, et il ne doit jamais fuir dans ce
qu'un autre compte est facturé : tarifer la commande d'un client passe donc
par `tier_from_roles()` sur les rôles de ce client.

### 4.4 Vide n'est pas zéro

Un champ de prix vide signifie « ce palier paie le prix public ». Un zéro
signifie « ce palier ne paie rien ». Les deux bouts de l'extension s'accordent
sur la distinction : `read_stored_price()` teste contre la chaîne vide et
`is_numeric()`, jamais `empty()` ; `apply_price()` supprime la méta sur un
champ vide et écrit `0` sur un zéro.

C'est la même famille de piège qu'un délai en jours où 0 doit valoir 0, et
elle se paie sur une facture.

### 4.5 Un prix négatif est refusé, pas ramené à zéro

À la lecture comme à l'écriture. Ramener à zéro, ce serait deviner que
quelqu'un voulait donner le produit ; ignorer une valeur qui ne peut pas être
juste est une réponse plus honnête.

### 4.6 Le prix public se lit en contexte `edit`

`Prices::get_public_price()` appelle `$product->get_price( 'edit' )`.
WooCommerce n'applique `woocommerce_product_get_price` — notre propre filtre
compris — qu'en contexte `view` : demander en `edit` lit le prix stocké et ne
peut pas être répondu par notre propre surcharge. Sans ça, l'affichage
comparerait le prix de palier à lui-même et la marge serait toujours nulle.

Il est vide pour un produit variable, qui n'a pas de prix unique : l'affichage
traite ce cas par fourchette, en laissant le formatage de WooCommerce
tranquille et en n'ajoutant que le libellé.

### 4.7 La bascule d'affichage ne change jamais ce qui est facturé

Trois endroits le garantissent, et c'est écrit dans les trois :
`Context::view_mode()` est consultée par `Display` et par personne d'autre ;
`Prices` ne la lit pas ; le panier affiche donc le prix de palier même quand
le catalogue affiche les prix publics. Une bascule qui changerait le prix
facturé serait une commande de remise déguisée en préférence d'affichage.

---

## 5. Modèle de données

| Clé | Portée | Contenu |
|---|---|---|
| `dahu_user_pricing_settings` | option | paliers, cartographie rôle → palier, rôles gestionnaires, affichage de la marge, bascule, purge à la désinstallation |
| `_dahu_user_pricing_price_<clé>` | méta de produit et de variation | prix du palier, chaîne décimale au format `wc_format_decimal()` |
| `dahu_user_pricing_preview` | cookie de session | clé du palier prévisualisé |
| `dahu_user_pricing_view` | cookie de session | `tier` ou `public` |

Les deux cookies sont des cookies de session : un aperçu encore actif le
lendemain matin est le mode de défaillance contre lequel ils protègent.

Aucune table créée. Aucun service externe contacté.

---

## 6. La revue adverse, et ce qu'elle a changé

Le banc passait **224 contrôles sur 224** quand une revue déléguée — un agent
qui n'avait pas écrit le code, à qui on a donné le code et pas les conclusions
— a sorti **onze défauts**, dont huit vérifiés en exécutant. Deux d'entre eux
facturaient le mauvais montant à quelqu'un. C'est la deuxième fois sur ce
chantier qu'une revue séparée trouve ce qu'un banc vert ne voit pas, et elle
reste obligatoire pour tout code qui touche à l'argent.

**Pourquoi le banc ne les voyait pas, et c'est le plus instructif.** Deux de ses
fonctions d'aide ne faisaient pas ce que fait la production :

- `up_order()` enveloppait `add_product()` dans `suspend()`/`resume()`. L'écran
  de commande de WooCommerce ne fait pas ça. Cette seule différence cachait le
  défaut n° 1 en entier.
- `up_as()` vidait le palier mémoïsé à la main. Aucun code de production n'a de
  raison de le faire. Cette seule différence cachait le défaut n° 6.

Une fonction d'aide qui « arrange » la mise en place teste un chemin que
personne ne prend. Les deux ont été alignées sur la production, et le §16 du
banc vérifie chacun des défauts par ce qu'il produisait.

| # | Ce qui se passait | Correction |
|---|---|---|
| 1 | L'AJAX de l'écran de commande tarifait la ligne **au palier du gestionnaire** — ou à celui qu'il prévisualisait — avant que la retarification ne passe. Réparé après coup dans la plupart des cas, **pas** pour une ligne que la retarification saute à raison. | `is_active()` reconnaît une requête d'écran de commande au nonce `order-item` de WooCommerce et coupe la tarification pour toute la requête. La ligne naît au prix public, jamais à celui de quelqu'un d'autre. |
| 2 | `reprice()` réécrivait **n'importe quelle** commande : payée, terminée, partiellement remboursée. Et chaque ajout d'article retarifait toute la commande en silence, écrasant une ligne corrigée à la main. | `may_reprice()` refuse une commande non modifiable ou remboursée, avec un filtre pour l'ouvrir. L'ajout d'article ne retarife **que les lignes ajoutées** ; tout retarifer est le travail du bouton explicite, et de lui seul. |
| 3 | La retarification posait `total = subtotal`, ce qui **annulait toute remise** : un coupon de 10 % restait affiché sur la commande, sa remise disparaissait. | Les coupons sont recalculés par `recalculate_coupons()` contre les nouveaux montants. |
| 4 | En vue publique, un produit variable montrait la fourchette **du palier** sous le libellé « prix public » : un revendeur venu lire le prix de revente lisait son propre prix d'achat. | La fourchette publique est reconstruite, tarification suspendue. Si elle ne peut pas l'être (produit groupé), **aucun libellé** n'est posé : un chiffre faux avec une étiquette sûre est pire qu'un chiffre sans étiquette. |
| 5 | Le refus de commande pendant un aperçu ne tenait que sur `woocommerce_after_checkout_validation`, c'est-à-dire sur le **paiement classique**. Le paiement en bloc, qui est celui par défaut, passait. | `woocommerce_store_api_cart_errors` en plus. Les deux paiements refusent. |
| 6 | Le palier mémoïsé n'était jamais invalidé : un changement d'utilisateur en cours de requête tarifait le second compte au palier du premier. | `Context::init()` accroche `set_current_user`. |
| 7 | Un `shop_manager` pouvait **supprimer** un palier mais pas enregistrer l'écran : `options.php` exige `manage_options`. Le chemin destructeur avait la barre la plus basse. | Filtre `option_page_capability_…` à `manage_woocommerce`. |
| 8 | `(int) $item->get_quantity()` : une quantité de 2,5 facturait 2. | `(float)`. |
| 9 | `suspend()`/`resume()` sans `try/finally` : une exception d'une source de prix tierce laissait la tarification coupée pour le reste de la requête, sans erreur visible. | `try/finally`. |
| 10 | La quantité était documentée comme atteignant le filtre de prix, et était jetée à la première ligne. Une source voulant des paliers de quantité recevait toujours 1. | La quantité est passée au filtre, qui a maintenant quatre arguments. |
| 11 | La clé d'un palier **pouvait** être renommée : `make_key()` rendait unique contre les clés déjà collectées, donc une rangée neuve soumise avant une rangée existante volait sa clé — et ses prix. | Deux passages : les clés existantes traversent intactes, les neuves sont rendues uniques contre elles. |

Deux points supplémentaires signalés et traités au passage : un rôle listé parmi
les gestionnaires n'avait **aucun plancher de capacité** (cocher `customer`
donnait l'aperçu de tous les paliers à tous les clients) — `is_manager()` exige
désormais `manage_woocommerce`, et l'écran ne propose plus que les rôles qui
l'ont ; et le tri par prix du catalogue, qui n'est pas corrigeable sans une
table de recherche par palier, est passé au §3 comme limite connue.

**Un point de la revue était faux**, vérifié dans la source de WooCommerce :
`wc_get_price_excluding_tax()` n'accepte pas d'argument `order`, il ne prend que
`qty` et `price`. Il n'y a donc rien à lui passer de plus. La revue le donnait
lui-même comme incertain.
