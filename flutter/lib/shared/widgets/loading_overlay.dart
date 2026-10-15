import 'package:flutter/material.dart';

/// Blocks interaction while [isLoading] is true.
///
/// Keeps the form visible underneath a light scrim and a thin top progress
/// bar — no floating cards, blur, or brand chrome.
class LoadingOverlay extends StatelessWidget {
  const LoadingOverlay({
    super.key,
    required this.isLoading,
    required this.child,
    this.message,
  });

  final bool isLoading;
  final Widget child;
  final String? message;

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        child,
        if (isLoading) ...[
          const ModalBarrier(dismissible: false, color: Color(0x66FFFFFF)),
          const Positioned(
            top: 0,
            left: 0,
            right: 0,
            child: SafeArea(
              bottom: false,
              child: LinearProgressIndicator(
                minHeight: 2,
                backgroundColor: Color(0xFFE8E8E8),
                color: Colors.black,
              ),
            ),
          ),
          if (message != null && message!.trim().isNotEmpty)
            Positioned(
              left: 24,
              right: 24,
              bottom: 32,
              child: SafeArea(
                top: false,
                child: Text(
                  message!,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w500,
                    color: Colors.grey.shade700,
                  ),
                ),
              ),
            ),
        ],
      ],
    );
  }
}
