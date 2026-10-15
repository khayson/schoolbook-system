import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:schoolbook/core/theme/app_theme.dart';

/// Semantic stroke icons (Hugeicons) — one place so screens stay consistent.
class AppIcons {
  AppIcons._();

  static const home = HugeIcons.strokeRoundedHome01;
  static const sales = HugeIcons.strokeRoundedInvoice03;
  static const schools = HugeIcons.strokeRoundedSchool01;
  static const more = HugeIcons.strokeRoundedGridView;
  static const moreVertical = HugeIcons.strokeRoundedMoreVertical;
  static const add = HugeIcons.strokeRoundedAdd01;
  static const scan = HugeIcons.strokeRoundedQrCode;
  static const logout = HugeIcons.strokeRoundedLogout01;
  static const payment = HugeIcons.strokeRoundedPayment01;
  static const receive = HugeIcons.strokeRoundedPackageReceive;
  static const products = HugeIcons.strokeRoundedBook02;
  static const stock = HugeIcons.strokeRoundedPackage;
  static const search = HugeIcons.strokeRoundedSearch01;
  static const alert = HugeIcons.strokeRoundedAlert02;
  static const chevronRight = HugeIcons.strokeRoundedArrowRight01;
  static const back = HugeIcons.strokeRoundedArrowLeft01;
  static const money = HugeIcons.strokeRoundedMoney01;
  static const wallet = HugeIcons.strokeRoundedCreditCard;
  static const delivery = HugeIcons.strokeRoundedDeliveryBox01;
  static const approvedList = HugeIcons.strokeRoundedBookmarkCheck01;
  static const edit = HugeIcons.strokeRoundedEdit02;
  static const remove = HugeIcons.strokeRoundedMinusSign;
  static const delete = HugeIcons.strokeRoundedDelete01;
  static const close = HugeIcons.strokeRoundedCancel01;
  static const check = HugeIcons.strokeRoundedTick01;
  static const share = HugeIcons.strokeRoundedShare01;
  static const visibility = HugeIcons.strokeRoundedView;
  static const visibilityOff = HugeIcons.strokeRoundedViewOff;
  static const hourglass = HugeIcons.strokeRoundedHourglass;
  static const mail = HugeIcons.strokeRoundedMail01;
  static const reports = HugeIcons.strokeRoundedAnalytics01;
  static const user = HugeIcons.strokeRoundedUser;
}

/// Thin stroke icon with NOTEE-friendly defaults.
class AppIcon extends StatelessWidget {
  const AppIcon(
    this.icon, {
    super.key,
    this.size = 22,
    this.color,
  });

  final IconData icon;
  final double size;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final resolved =
        color ?? IconTheme.of(context).color ?? AppColors.ink;
    return HugeIcon(icon: icon, color: resolved, size: size);
  }
}
