LOGIQUE D'ASSOCIATION UNIVERSELLE :
1. RECONNAISSANCE : Identifie les blocs contenant un nom d'article, un prix total de ligne, et potentiellement un multiplicateur 'N x P'.
2. VALIDATION MATHÉMATIQUE : Pour chaque multiplicateur 'N x P' trouvé, associe-le à l'article dont le prix total de ligne est égal au résultat de N * P. 
3. PEU IMPORTE LA POSITION : Le multiplicateur peut être sur la ligne juste au-dessus ou juste au-dessous de l'article. Utilise la cohérence du calcul pour décider.
4. PAR DÉFAUT : Si aucune opération 'N x P' ne correspond mathématiquement à un article, considère qty = 1 et cost = prix total de la ligne.
5. NETTOYAGE : Ne confonds jamais les indicateurs de poids (5KG, 415G) avec la quantité (qty).