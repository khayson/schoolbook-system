import 'package:flutter/material.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';

/// Sentinel so a sheet can return "clear" without looking like dismiss.
class ClearOption {
  const ClearOption._();
  static const instance = ClearOption._();
}

/// Opens the NOTEE option sheet. Returns the picked value, `null` when cleared,
/// or [unchanged] when the sheet is dismissed without a choice.
Future<T?> showOptionPickerSheet<T>({
  required BuildContext context,
  required String label,
  required T? value,
  required List<(T, String)> options,
  String clearLabel = 'Any',
  bool allowClear = true,
  T? unchanged,
}) async {
  final result = await showModalBottomSheet<Object>(
    context: context,
    backgroundColor: AppColors.surface,
    isScrollControlled: true,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
    ),
    builder: (context) => _OptionPickerSheet(
      label: label,
      value: value,
      options: options,
      clearLabel: clearLabel,
      allowClear: allowClear,
    ),
  );
  if (result == null) return unchanged ?? value;
  if (result == ClearOption.instance) return null;
  return result as T;
}

/// Compact labeled field that opens a bottom-sheet option list.
class OptionPicker<T> extends StatelessWidget {
  const OptionPicker({
    super.key,
    required this.label,
    required this.value,
    required this.options,
    required this.onChanged,
    this.clearLabel = 'Any',
    this.allowClear = true,
  });

  final String label;
  final T? value;

  /// Non-null options only. A leading clear row is added when [allowClear] is true.
  final List<(T, String)> options;
  final ValueChanged<T?> onChanged;
  final String clearLabel;
  final bool allowClear;

  Future<void> _open(BuildContext context) async {
    final result = await showModalBottomSheet<Object>(
      context: context,
      backgroundColor: AppColors.surface,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (context) => _OptionPickerSheet(
        label: label,
        value: value,
        options: options,
        clearLabel: clearLabel,
        allowClear: allowClear,
      ),
    );
    if (result == null) return;
    if (result == ClearOption.instance) {
      if (allowClear) onChanged(null);
    } else {
      onChanged(result as T);
    }
  }

  @override
  Widget build(BuildContext context) {
    final display = value == null
        ? clearLabel
        : options
              .firstWhere(
                (o) => o.$1 == value,
                orElse: () => (value as T, clearLabel),
              )
              .$2;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label.toUpperCase(),
          style: const TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w800,
            letterSpacing: 0.8,
            color: AppColors.mute,
          ),
        ),
        const SizedBox(height: 8),
        Material(
          color: AppColors.wash,
          borderRadius: BorderRadius.circular(16),
          child: InkWell(
            borderRadius: BorderRadius.circular(16),
            onTap: () => _open(context),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      display,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                  const AppIcon(
                    AppIcons.chevronRight,
                    size: 18,
                    color: AppColors.ink,
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _OptionPickerSheet<T> extends StatefulWidget {
  const _OptionPickerSheet({
    required this.label,
    required this.value,
    required this.options,
    required this.clearLabel,
    required this.allowClear,
  });

  final String label;
  final T? value;
  final List<(T, String)> options;
  final String clearLabel;
  final bool allowClear;

  @override
  State<_OptionPickerSheet<T>> createState() => _OptionPickerSheetState<T>();
}

class _OptionPickerSheetState<T> extends State<_OptionPickerSheet<T>> {
  final _query = TextEditingController();
  final _searchFocus = FocusNode();

  @override
  void dispose() {
    _query.dispose();
    _searchFocus.dispose();
    super.dispose();
  }

  bool _selected(Object option) {
    if (option == ClearOption.instance) return widget.value == null;
    return option == widget.value;
  }

  List<(Object, String)> get _rows {
    final q = _query.text.trim().toLowerCase();
    final filtered = q.isEmpty
        ? widget.options
        : widget.options
              .where((o) => o.$2.toLowerCase().contains(q))
              .toList();
    return [
      if (widget.allowClear && q.isEmpty)
        (ClearOption.instance, widget.clearLabel),
      for (final o in filtered) (o.$1 as Object, o.$2),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final maxHeight = MediaQuery.sizeOf(context).height * 0.75;
    final bottom = MediaQuery.paddingOf(context).bottom;
    final inset = MediaQuery.viewInsetsOf(context).bottom;
    final rows = _rows;

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(bottom: inset),
        child: ConstrainedBox(
          constraints: BoxConstraints(maxHeight: maxHeight),
          child: Padding(
            padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 12),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Center(
                  child: Container(
                    width: 40,
                    height: 4,
                    decoration: BoxDecoration(
                      color: AppColors.line,
                      borderRadius: BorderRadius.circular(999),
                    ),
                  ),
                ),
                const SizedBox(height: 16),
                Text(
                  widget.label,
                  style: const TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  key: const Key('option_picker_search'),
                  controller: _query,
                  focusNode: _searchFocus,
                  autofocus: widget.options.length >= 8,
                  textInputAction: TextInputAction.search,
                  decoration: InputDecoration(
                    hintText: 'Search ${widget.label.toLowerCase()}…',
                    prefixIcon: const Padding(
                      padding: EdgeInsets.only(left: 14, right: 8),
                      child: AppIcon(AppIcons.search, size: 20),
                    ),
                    prefixIconConstraints: const BoxConstraints(
                      minWidth: 44,
                      minHeight: 44,
                    ),
                    suffixIcon: _query.text.isEmpty
                        ? null
                        : IconButton(
                            onPressed: () {
                              _query.clear();
                              setState(() {});
                            },
                            icon: const AppIcon(AppIcons.close, size: 18),
                          ),
                  ),
                  onChanged: (_) => setState(() {}),
                ),
                const SizedBox(height: 12),
                Flexible(
                  child: rows.isEmpty
                      ? const Padding(
                          padding: EdgeInsets.symmetric(vertical: 24),
                          child: Text(
                            'No matches',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              fontWeight: FontWeight.w700,
                              color: AppColors.mute,
                            ),
                          ),
                        )
                      : ListView.separated(
                          shrinkWrap: true,
                          keyboardDismissBehavior:
                              ScrollViewKeyboardDismissBehavior.onDrag,
                          itemCount: rows.length,
                          separatorBuilder: (_, _) =>
                              const SizedBox(height: 8),
                          itemBuilder: (context, i) {
                            final o = rows[i];
                            final selected = _selected(o.$1);
                            return Material(
                              color: selected
                                  ? AppColors.ink
                                  : AppColors.wash,
                              borderRadius: BorderRadius.circular(14),
                              child: InkWell(
                                borderRadius: BorderRadius.circular(14),
                                onTap: () => Navigator.pop(context, o.$1),
                                child: Padding(
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 16,
                                    vertical: 14,
                                  ),
                                  child: Row(
                                    children: [
                                      Expanded(
                                        child: Text(
                                          o.$2,
                                          style: TextStyle(
                                            fontWeight: FontWeight.w800,
                                            color: selected
                                                ? AppColors.lime
                                                : AppColors.ink,
                                          ),
                                        ),
                                      ),
                                      if (selected)
                                        const AppIcon(
                                          AppIcons.check,
                                          size: 18,
                                          color: AppColors.lime,
                                        ),
                                    ],
                                  ),
                                ),
                              ),
                            );
                          },
                        ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
