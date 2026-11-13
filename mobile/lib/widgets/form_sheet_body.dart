import 'package:flutter/material.dart';

/// Scrolls modal content while keeping controls above the system insets.
class FormSheetBody extends StatelessWidget {
  const FormSheetBody({
    super.key,
    required this.child,
    this.padding = EdgeInsets.zero,
  });

  final Widget child;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) => SafeArea(
    top: false,
    child: Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(padding: padding, child: child),
    ),
  );
}
