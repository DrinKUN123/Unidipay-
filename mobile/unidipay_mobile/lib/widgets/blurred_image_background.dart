import 'dart:ui';

import 'package:flutter/material.dart';

class BlurredImageBackground extends StatelessWidget {
  const BlurredImageBackground({
    super.key,
    required this.assetPath,
    this.overlayOpacity = 0.46,
    this.blurSigma = 14,
  });

  final String assetPath;
  final double overlayOpacity;
  final double blurSigma;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    final fallback = Container(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            theme.colorScheme.primaryContainer.withValues(alpha: 0.35),
            theme.colorScheme.surface,
          ],
        ),
      ),
    );

    return IgnorePointer(
      ignoring: true,
      child: Stack(
        fit: StackFit.expand,
        children: [
          Positioned.fill(
            child: ImageFiltered(
              imageFilter: ImageFilter.blur(
                sigmaX: blurSigma,
                sigmaY: blurSigma,
              ),
              child: Image.asset(
                assetPath,
                fit: BoxFit.cover,
                filterQuality: FilterQuality.high,
                errorBuilder: (_, __, ___) => fallback,
              ),
            ),
          ),
          Positioned.fill(
            child: Container(
              color: Colors.white.withValues(alpha: overlayOpacity),
            ),
          ),
        ],
      ),
    );
  }
}