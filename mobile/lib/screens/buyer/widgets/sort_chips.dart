// lib/screens/buyer/widgets/sort_chips.dart
//
// Shared sort chip row for CategoryProductsScreen and SearchScreen.
// Single horizontally scrolling row: Default, Price: Low to High,
// Price: High to Low — client-side only, applied via sortProducts().
// No "Newest": Product carries no created/updated timestamp.

import 'package:flutter/material.dart';

import '../../../theme/app_theme.dart';
import '../../../utils/product_sort.dart';

class ProductSortChips extends StatelessWidget {
  const ProductSortChips({
    super.key,
    required this.sort,
    required this.onChanged,
  });

  final ProductSort sort;
  final ValueChanged<ProductSort> onChanged;

  static const _options = [
    (ProductSort.featured, 'Default'),
    (ProductSort.priceAsc, 'Price: Low to High'),
    (ProductSort.priceDesc, 'Price: High to Low'),
  ];

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 20),
      child: Row(
        children: [
          for (var i = 0; i < _options.length; i++) ...[
            if (i > 0) const SizedBox(width: 8),
            ChoiceChip(
              label: Text(_options[i].$2),
              selected: sort == _options[i].$1,
              onSelected: (_) => onChanged(_options[i].$1),
            ),
          ],
        ],
      ),
    );
  }
}
