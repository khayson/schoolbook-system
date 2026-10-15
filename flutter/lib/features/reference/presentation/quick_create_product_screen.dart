import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/core/validators.dart';
import 'package:schoolbook/features/catalog/data/lookups_repository.dart';
import 'package:schoolbook/features/catalog/domain/lookup_models.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/reference/domain/reference_book.dart';
import 'package:schoolbook/features/reference/presentation/quick_create_controller.dart';
import 'package:schoolbook/features/reference/presentation/reference_catalog.dart';
import 'package:schoolbook/shared/widgets/app_chrome.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';
import 'package:schoolbook/shared/widgets/loading_overlay.dart';
import 'package:schoolbook/shared/widgets/option_picker.dart';

/// Add a product from an approved title: only what the list does not say is asked
/// (variant, cost, price, opening stock, code; a class for band-only titles). Pops
/// with the created product when opened with `push` (receive stock, scan); otherwise
/// opens it.
class QuickCreateProductScreen extends StatelessWidget {
  const QuickCreateProductScreen({
    super.key,
    required this.bookId,
    this.code,
    this.forReceive = false,
  });

  final int bookId;

  /// A scanned code to attach to the new product.
  final String? code;

  /// Opened from Receive stock: no opening stock here, the receipt line has the quantity.
  final bool forReceive;

  @override
  Widget build(BuildContext context) {
    final book = context.watch<ReferenceCatalog>().byId(bookId);
    if (book == null) {
      return const AppPage(
        title: 'Add from approved list',
        body: EmptyState(
          title: 'Title not found',
          subtitle: 'Refresh the approved list and try again.',
        ),
      );
    }

    return ChangeNotifierProvider(
      create: (context) => QuickCreateController(
        products: context.read<ProductsRepository>(),
        pendingStore: context.read<PendingSubmissionStore>(),
        book: book,
      ),
      child: _QuickCreateForm(book: book, code: code, forReceive: forReceive),
    );
  }
}

class _QuickCreateForm extends StatefulWidget {
  const _QuickCreateForm({
    required this.book,
    required this.code,
    required this.forReceive,
  });

  final ReferenceBook book;
  final String? code;
  final bool forReceive;

  @override
  State<_QuickCreateForm> createState() => _QuickCreateFormState();
}

class _QuickCreateFormState extends State<_QuickCreateForm> {
  final _formKey = GlobalKey<FormState>();
  final _variant = TextEditingController();
  final _cost = TextEditingController();
  final _price = TextEditingController();
  final _opening = TextEditingController();
  late final TextEditingController _code = TextEditingController(
    text: widget.code ?? '',
  );

  List<LevelLookup> _levels = const [];
  List<NamedLookup> _subjects = const [];
  List<LanguageLookup> _languages = const [];
  int? _levelId;
  int? _subjectId;
  int? _languageId;
  String? _levelError;
  String? _subjectError;
  String? _languageError;

  static const _bandLevels = {
    'kg': {'Creche', 'KG 1', 'KG 2'},
    'lower_primary': {'Primary 1', 'Primary 2', 'Primary 3'},
    'upper_primary': {'Primary 4', 'Primary 5', 'Primary 6'},
    'jhs': {'JHS 1', 'JHS 2', 'JHS 3'},
  };

  @override
  void initState() {
    super.initState();
    _loadLookups();
  }

  Future<void> _loadLookups() async {
    final lookups = context.read<LookupsRepository>();
    final book = widget.book;
    final levels = book.needsLevel
        ? await lookups.fetchLevels()
        : <LevelLookup>[];
    final subjects = book.subjectId == null
        ? await lookups.fetchSubjects()
        : <NamedLookup>[];
    final languages = book.languageId == null
        ? await lookups.fetchLanguages()
        : <LanguageLookup>[];
    if (!mounted) return;
    final band = _bandLevels[book.band];
    final inBand = band == null
        ? levels
        : levels.where((l) => band.contains(l.name)).toList();
    setState(() {
      _levels = inBand.isEmpty ? levels : inBand;
      _subjects = subjects;
      _languages = languages;
    });
  }

  @override
  void dispose() {
    for (final c in [_variant, _cost, _price, _opening, _code]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      _levelError =
          widget.book.needsLevel && _levelId == null ? 'Choose the class' : null;
      _subjectError = widget.book.subjectId == null && _subjectId == null
          ? 'Choose the subject'
          : null;
      _languageError = widget.book.languageId == null && _languageId == null
          ? 'Choose the language'
          : null;
    });
    if (!(_formKey.currentState?.validate() ?? false) ||
        _levelError != null ||
        _subjectError != null ||
        _languageError != null) {
      return;
    }
    final controller = context.read<QuickCreateController>();
    final opening = widget.forReceive
        ? 0
        : int.tryParse(_opening.text.trim()) ?? 0;
    final payload = QuickCreateController.buildPayload(
      book: widget.book,
      variant: _variant.text,
      levelId: _levelId,
      subjectId: _subjectId,
      languageId: _languageId,
      costPesewas: Money.parseGhsToPesewas(_cost.text)!,
      pricePesewas: Money.parseGhsToPesewas(_price.text)!,
      openingStock: opening,
    );
    final product = await controller.submit(payload, code: _code.text);
    if (product == null || !mounted) return;

    context.read<ReferenceCatalog>().noteProductAdded(widget.book.id, opening);
    AppToast.show(
      context,
      controller.codeWarning ?? 'Added ${product.title} (${product.sku})',
      type: controller.codeWarning == null
          ? ToastType.success
          : ToastType.warning,
      duration: Duration(seconds: controller.codeWarning == null ? 3 : 8),
    );
    if (context.canPop()) {
      context.pop(product);
    } else {
      context.go('/products/${product.id}');
    }
  }

  @override
  Widget build(BuildContext context) {
    final controller = context.watch<QuickCreateController>();
    final book = widget.book;
    final error = controller.error;

    return LoadingOverlay(
      isLoading: controller.saving,
      message: 'Adding product…',
      child: AppPage(
        title: 'Add from list',
        subtitle: 'Approved title → your catalog',
        body: Form(
          key: _formKey,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
            children: [
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(18),
                decoration: BoxDecoration(
                  color: AppColors.ink,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      book.title,
                      key: const Key('qc_title'),
                      style: const TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.w900,
                        color: Colors.white,
                        height: 1.25,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      book.subtitle,
                      style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        color: Colors.white.withValues(alpha: 0.65),
                        height: 1.35,
                      ),
                    ),
                    if (book.author != null) ...[
                      const SizedBox(height: 8),
                      Text(
                        book.author!,
                        style: TextStyle(
                          fontSize: 12.5,
                          fontWeight: FontWeight.w700,
                          color: AppColors.lime.withValues(alpha: 0.9),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: 18),
              TextFormField(
                key: const Key('qc_variant'),
                controller: _variant,
                decoration: const InputDecoration(
                  labelText: 'Variant (optional)',
                  hintText: "Learner's Book, Teacher's Guide…",
                ),
                maxLength: 100,
              ),
              if (book.needsLevel) ...[
                const SizedBox(height: 4),
                OptionPicker<int>(
                  key: const Key('qc_level'),
                  label: 'Class',
                  value: _levelId,
                  allowClear: false,
                  clearLabel: 'Choose class',
                  options: _levels.map((l) => (l.id, l.name)).toList(),
                  onChanged: (v) => setState(() {
                    _levelId = v;
                    _levelError = null;
                  }),
                ),
                if (book.level != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Text(
                      'Listed for ${book.level}: choose the class.',
                      style: const TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                        color: AppColors.mute,
                      ),
                    ),
                  ),
                if (_levelError != null || error?.fieldError('level_id') != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Text(
                      _levelError ?? error!.fieldError('level_id')!,
                      style: const TextStyle(
                        color: AppColors.danger,
                        fontWeight: FontWeight.w700,
                        fontSize: 12.5,
                      ),
                    ),
                  ),
              ],
              if (book.subjectId == null) ...[
                const SizedBox(height: 14),
                OptionPicker<int>(
                  key: const Key('qc_subject'),
                  label: 'Subject',
                  value: _subjectId,
                  allowClear: false,
                  clearLabel: 'Choose subject',
                  options: _subjects.map((s) => (s.id, s.name)).toList(),
                  onChanged: (v) => setState(() {
                    _subjectId = v;
                    _subjectError = null;
                  }),
                ),
                if (_subjectError != null ||
                    error?.fieldError('subject_id') != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Text(
                      _subjectError ?? error!.fieldError('subject_id')!,
                      style: const TextStyle(
                        color: AppColors.danger,
                        fontWeight: FontWeight.w700,
                        fontSize: 12.5,
                      ),
                    ),
                  ),
              ],
              if (book.languageId == null) ...[
                const SizedBox(height: 14),
                OptionPicker<int>(
                  key: const Key('qc_language'),
                  label: 'Language',
                  value: _languageId,
                  allowClear: false,
                  clearLabel: 'Choose language',
                  options: _languages.map((l) => (l.id, l.name)).toList(),
                  onChanged: (v) => setState(() {
                    _languageId = v;
                    _languageError = null;
                  }),
                ),
                if (_languageError != null ||
                    error?.fieldError('language_id') != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Text(
                      _languageError ?? error!.fieldError('language_id')!,
                      style: const TextStyle(
                        color: AppColors.danger,
                        fontWeight: FontWeight.w700,
                        fontSize: 12.5,
                      ),
                    ),
                  ),
              ],
              const SizedBox(height: 14),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: TextFormField(
                      key: const Key('qc_cost'),
                      controller: _cost,
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: true,
                      ),
                      decoration: InputDecoration(
                        labelText: 'Cost price',
                        prefixText: 'GHS ',
                        errorText: error?.fieldError('cost_price'),
                      ),
                      validator: (v) => Validators.ghsAmount(v, allowZero: true),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: TextFormField(
                      key: const Key('qc_price'),
                      controller: _price,
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: true,
                      ),
                      decoration: InputDecoration(
                        labelText: 'Selling price',
                        prefixText: 'GHS ',
                        errorText: error?.fieldError('selling_price'),
                      ),
                      validator: (v) => Validators.ghsAmount(v, allowZero: true),
                    ),
                  ),
                ],
              ),
              if (!widget.forReceive) ...[
                const SizedBox(height: 12),
                TextFormField(
                  key: const Key('qc_opening'),
                  controller: _opening,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(
                    labelText: 'Opening stock (optional)',
                    helperText: 'Received into stock at the cost price.',
                    errorText: error?.fieldError('opening_stock'),
                  ),
                  validator: Validators.optionalQuantity,
                ),
              ],
              const SizedBox(height: 12),
              TextFormField(
                key: const Key('qc_code'),
                controller: _code,
                decoration: InputDecoration(
                  labelText: 'Barcode or ISBN (optional)',
                  suffixIcon: IconButton(
                    key: const Key('qc_scan'),
                    tooltip: 'Scan',
                    icon: const AppIcon(AppIcons.scan, size: 20),
                    onPressed: () async {
                      final scanned = await context.push<String>(
                        '/products/scan?capture=1',
                      );
                      if (scanned != null) _code.text = scanned;
                    },
                  ),
                ),
                validator: Validators.optionalCode,
              ),
              if (error != null) ...[
                const SizedBox(height: 12),
                Text(
                  '${describeApiError(error).title}: ${describeApiError(error).body}',
                  key: const Key('qc_error'),
                  style: const TextStyle(
                    color: AppColors.danger,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
              const SizedBox(height: 20),
              FilledButton(
                key: const Key('qc_save'),
                onPressed: controller.saving ? null : _save,
                child: Text(
                  controller.saving ? 'Saving…' : 'Add to my products',
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
