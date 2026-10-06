import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/validators.dart';
import 'package:schoolbook/features/catalog/data/lookups_repository.dart';
import 'package:schoolbook/features/catalog/domain/lookup_models.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/reference/domain/reference_book.dart';
import 'package:schoolbook/features/reference/presentation/quick_create_controller.dart';
import 'package:schoolbook/features/reference/presentation/reference_catalog.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

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
      return Scaffold(
        appBar: AppBar(title: const Text('Add from approved list')),
        body: const EmptyState(
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
    if (!(_formKey.currentState?.validate() ?? false)) return;
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
    final messenger = ScaffoldMessenger.of(context);
    messenger.showSnackBar(
      SnackBar(
        content: Text(
          controller.codeWarning ?? 'Added ${product.title} (${product.sku})',
        ),
        duration: Duration(seconds: controller.codeWarning == null ? 3 : 8),
      ),
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
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Add from approved list')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(
              book.title,
              style: theme.textTheme.titleLarge,
              key: const Key('qc_title'),
            ),
            const SizedBox(height: 4),
            Text(book.subtitle, style: theme.textTheme.bodyMedium),
            if (book.author != null)
              Text(book.author!, style: theme.textTheme.bodySmall),
            const SizedBox(height: 16),
            TextFormField(
              key: const Key('qc_variant'),
              controller: _variant,
              decoration: const InputDecoration(
                labelText: 'Variant (optional)',
                hintText: "Learner's Book, Teacher's Guide, Workbook…",
                border: OutlineInputBorder(),
              ),
              maxLength: 100,
            ),
            if (book.needsLevel) ...[
              const SizedBox(height: 4),
              DropdownButtonFormField<int>(
                key: const Key('qc_level'),
                initialValue: _levelId,
                decoration: InputDecoration(
                  labelText: 'Class',
                  helperText: book.level == null
                      ? null
                      : 'Listed for ${book.level}: choose the class.',
                  border: const OutlineInputBorder(),
                  errorText: error?.fieldError('level_id'),
                ),
                items: [
                  for (final l in _levels)
                    DropdownMenuItem(value: l.id, child: Text(l.name)),
                ],
                onChanged: (v) => setState(() => _levelId = v),
                validator: (v) => v == null ? 'Choose the class' : null,
              ),
            ],
            if (book.subjectId == null) ...[
              const SizedBox(height: 12),
              DropdownButtonFormField<int>(
                key: const Key('qc_subject'),
                initialValue: _subjectId,
                decoration: InputDecoration(
                  labelText: 'Subject',
                  border: const OutlineInputBorder(),
                  errorText: error?.fieldError('subject_id'),
                ),
                items: [
                  for (final s in _subjects)
                    DropdownMenuItem(value: s.id, child: Text(s.name)),
                ],
                onChanged: (v) => setState(() => _subjectId = v),
                validator: (v) => v == null ? 'Choose the subject' : null,
              ),
            ],
            if (book.languageId == null) ...[
              const SizedBox(height: 12),
              DropdownButtonFormField<int>(
                key: const Key('qc_language'),
                initialValue: _languageId,
                decoration: InputDecoration(
                  labelText: 'Language',
                  border: const OutlineInputBorder(),
                  errorText: error?.fieldError('language_id'),
                ),
                items: [
                  for (final l in _languages)
                    DropdownMenuItem(value: l.id, child: Text(l.name)),
                ],
                onChanged: (v) => setState(() => _languageId = v),
                validator: (v) => v == null ? 'Choose the language' : null,
              ),
            ],
            const SizedBox(height: 12),
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
                      border: const OutlineInputBorder(),
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
                      border: const OutlineInputBorder(),
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
                  border: const OutlineInputBorder(),
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
                border: const OutlineInputBorder(),
                suffixIcon: IconButton(
                  key: const Key('qc_scan'),
                  tooltip: 'Scan',
                  icon: const Icon(Icons.qr_code_scanner),
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
                style: TextStyle(color: theme.colorScheme.error),
              ),
            ],
            const SizedBox(height: 16),
            FilledButton(
              key: const Key('qc_save'),
              onPressed: controller.saving ? null : _save,
              style: FilledButton.styleFrom(
                minimumSize: const Size.fromHeight(52),
              ),
              child: Text(controller.saving ? 'Saving…' : 'Add to my products'),
            ),
          ],
        ),
      ),
    );
  }
}
