# Dahu User Pricing

Des prix par compte pour WooCommerce : des paliers tarifaires que vous nommez,
un prix par palier sur chaque produit et chaque variation, le prix public et la
marge de revente affichés à côté, et les commandes saisies à la main tarifées
pour le bon client.

Licence MIT. Nécessite WordPress 6.0, PHP 7.4 et WooCommerce 7.0.

*La version anglaise de ce document est dans `README.md`. Les deux disent la
même chose ; en cas d'écart, c'est l'anglaise qui fait foi, parce que c'est elle
qui part sur wordpress.org.*

## À quoi ça sert

Une boutique qui vend le même catalogue au grand public et à des revendeurs a
deux prix par produit, et le second n'est pas une remise sur le premier. C'est
un prix négocié qui dépend de qui est connecté, il doit rester visible à côté du
prix public pour que le revendeur voie ce qu'il gagne, et il doit survivre aux
endroits où WooCommerce tarife sans demander qui est le client.

Ce dernier point est l'essentiel du travail. Le catalogue, le panier et la
commande passent par des filtres de prix : c'est le cas simple. Une commande
qu'un gestionnaire saisit à la main au téléphone, non : la ligne est tarifée
depuis le catalogue par une requête dont l'utilisateur courant est le
gestionnaire, et le montant est **écrit sur la ligne**, là où aucun filtre ne
peut l'atteindre. La commande téléphonique d'un revendeur sort donc au prix
public tant que rien ne la retarife explicitement.

## Les paliers

Un palier est un nom que vous choisissez, plus les deux libellés affichés devant
un prix :

| Nom | Libellé du prix du palier | Libellé du prix public |
|---|---|---|
| Revendeur | `Votre prix :` | `Prix conseillé :` |
| Grossiste | `Tarif grossiste :` | `PVC :` |

Pas de liste intégrée, pas de limite. Associez un rôle WordPress à un palier et
les comptes qui le portent sont facturés au prix de ce palier.

La **clé** d'un palier est dérivée de son nom une fois, à la création, et plus
jamais. Les prix vivent dans une méta de produit nommée d'après la clé : une clé
qui changerait rendrait inaccessibles tous les prix classés sous l'ancienne,
sans rien à l'écran pour le dire. Renommez le libellé librement ; la clé cesse
simplement de lui ressembler.

Les libellés sont débarrassés de leurs espaces de début et de fin à
l'enregistrement, et l'espace entre un libellé et un montant vient de la feuille
de style. Un espacement qui dépendrait d'un espace final à ne pas oublier serait
invisible dans le champ et perdu au premier enregistrement.

**Rien ne se passe tant qu'un palier n'existe pas et qu'aucun rôle ne lui est
associé.** Activer l'extension ne change aucun prix.

## Les prix

Chaque produit gagne un champ par palier, parmi les champs de prix de
WooCommerce. Les variations ont les leurs, et une variation laissée vide hérite
de son parent.

- **Vide** signifie que ce palier paie le prix public.
- **Zéro** signifie que ce palier ne paie rien.

Les deux sont des instructions différentes, et les deux bouts de l'extension
s'accordent dessus — `empty()` transformerait silencieusement « gratuit » en
« plein tarif », et c'est le genre de défaut qui ne se voit que sur une facture.

Seul `price` est surchargé ; `regular_price` est laissé tranquille. C'est ce qui
garde le prix public lisible pendant qu'un prix de palier s'applique, et c'est à
partir de lui que la marge est calculée.

Un prix soldé **ne s'empile pas** sur un prix de palier : les cumuler ferait
dépendre le prix d'un revendeur d'une promotion qu'il n'a jamais négociée. Une
boutique qui veut le plus bas des deux le dit en trois lignes sur le filtre
`dahu_user_pricing_price`.

## Ce que voit le client

Pour un compte ayant un palier, sur un produit qui a un prix de palier :

```
Votre prix : 308,21 €   Prix conseillé : 474,17 €   Marge : 35,0 %
```

Le prix public n'apparaît que s'il diffère du prix du palier — deux montants
identiques dont l'un est barré se lisent comme une remise qui n'existe pas. La
marge est désactivée par défaut : c'est une information commerciale, pas une
décoration.

Tout est assemblé côté serveur. La version dont celle-ci est issue le faisait en
JavaScript, avec cinq scripts qui parcouraient la page rendue pour retrouver les
montants par nom de classe ; ça fonctionne sur un seul thème, affiche brièvement
le mauvais prix avant de s'exécuter, et est invisible pour tout ce qui lit la
page sans exécuter de script.

## La bascule d'affichage

`[dahu_user_pricing_view_switch]` affiche un lien qui fait basculer un compte
entre ses propres prix et les prix publics. Rien ne le place automatiquement :
une extension qui injecterait une commande dans l'en-tête d'un thème devinerait
une mise en page qu'elle ne voit pas.

**Elle ne change que l'affichage.** Le panier facture toujours le prix propre au
compte, et continue d'afficher ce prix-là même quand le catalogue montre les
prix publics, parce que le rôle du panier est de dire ce que ce compte va payer.
Une bascule qui changerait le prix facturé serait une commande de remise
déguisée en préférence d'affichage.

## L'aperçu d'un palier

Les rôles listés comme gestionnaires obtiennent un menu dans la barre d'outils
pour voir la boutique comme n'importe quel palier — le seul moyen fiable de
vérifier qu'un palier est bien configuré, et plus rapide que de garder un second
compte dans un second navigateur.

L'aperçu est fidèle, ce qui le rend dangereux : pendant un aperçu, les prix sont
réellement ceux de ce palier, au panier comme au catalogue. Aucune commande ne
peut donc être passée tant qu'il tourne. Une bannière le dit en haut de la page,
au panier et au paiement, et le paiement refuse avec un message qui nomme la
sortie. Un avertissement seul est ce qu'avait la version précédente, et un
avertissement n'arrête pas quelqu'un de fatigué à vingt-et-une heures.

Un aperçu force **une seule chose** : le palier. Il ne touche ni à un rôle ni à
une capacité.

## Les commandes saisies dans l'administration

Ajouter un produit à une commande retarife les lignes ajoutées depuis le client
de la commande, hors taxes, et laisse la TVA à `WC_Order::calculate_totals()`,
qui connaît l'adresse et la classe de taxe du client. Cette extension
n'implémente pas la TVA.

Sur l'écran « Ajouter une commande », le client choisi n'est écrit dans le
brouillon qu'au clic sur « Créer » : le navigateur envoie donc la valeur
courante avec la requête d'ajout, et le serveur la persiste quelques secondes
plus tôt.

Changer le client d'une commande qui a déjà des lignes fait apparaître un bouton
**Retarifer les lignes pour ce client**. Ce n'est pas automatique : une ligne a
pu être corrigée à la main, et une retarification silencieuse la jetterait sans
annulation possible.

Une commande payée, remboursée ou qui n'est plus modifiable n'est **jamais**
retarifée : ses montants sont le relevé d'une transaction qui a eu lieu.

Les lignes dont le prix est porté par une ligne parente — composants de lots et
de composites — sont laissées tranquilles. L'extension ne dépend d'aucune de ces
extensions : elle reconnaît leurs métas pour ne pas les abîmer.

## Pour les développeurs

```php
// Fournir un prix de palier depuis ailleurs. Renvoyer null pour « aucun prix ».
add_filter( 'dahu_user_pricing_price', function ( $price, $product, $tier, $quantity ) {
	return $price;
}, 10, 4 );

// Les métas qui marquent une ligne dont le prix appartient à une ligne parente.
add_filter( 'dahu_user_pricing_child_line_metas', function ( $metas ) {
	$metas[] = '_mon_lot_parent';
	return $metas;
} );

// Autoriser ou refuser la retarification d'une commande.
add_filter( 'dahu_user_pricing_may_reprice_order', function ( $allowed, $order ) {
	return $allowed;
}, 10, 2 );
```

```php
dahu_user_pricing_get_tier( $user_id = null );          // clé de palier, ou null
dahu_user_pricing_get_price_labels( $tier = null );      // array{public, tier}
dahu_user_pricing_resolve_line_pricing( $product, $user, $quantity = 1 );
```

La dernière est ce dont a besoin du code qui construit une commande : un devis,
un renouvellement, un import écrivent tous les montants sur la ligne eux-mêmes,
là où aucun filtre de prix ne s'applique — ils doivent donc demander le prix au
lieu d'espérer.

## À la désinstallation

L'option de l'extension part. Les prix de palier posés sur vos produits
**restent** : il peut y en avoir des milliers, ils ont été saisis à la main, et
leur suppression est irréversible. Un réglage sur l'écran de tarification les
supprime à la place, désactivé par défaut.

Supprimer un seul palier fonctionne de la même façon : le palier quitte les
réglages et ses rôles sont démappés, tous les prix classés dessous restent, et
recréer le palier sous le même nom les retrouve.
