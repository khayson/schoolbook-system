import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/catalog/data/lookups_repository.dart';
import 'package:schoolbook/features/catalog/domain/lookup_models.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/shared/widgets/app_chrome.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';
import 'package:schoolbook/shared/widgets/loading_overlay.dart';
import 'package:schoolbook/shared/widgets/option_picker.dart';

class ProductFormScreen extends StatefulWidget {
  const ProductFormScreen({super.key, this.productId});

  final int? productId;

  bool get isEditing => productId != null;

  @override
  State<ProductFormScreen> createState() => _ProductFormScreenState();
}

class _ProductFormScreenState extends State<ProductFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _skuController = TextEditingController();
  final _titleController = TextEditingController();
  final _isbnController = TextEditingController();
  final _barcodeController = TextEditingController();
  final _editionController = TextEditingController();
  final _costController = TextEditingController();
  final _priceController = TextEditingController();
  final _reorderController = TextEditingController(text: '5');

  List<LevelLookup> _levels = [];
  List<NamedLookup> _subjects = [];
  List<LanguageLookup> _languages = [];
  List<NamedLookup> _publishers = [];

  int? _levelId;
  int? _subjectId;
  int? _languageId;
  int? _publisherId;
  bool _isActive = true;
  bool _loading = true;
  bool _saving = false;
  String? _error;
  String? _levelError;
  String? _subjectError;
  String? _languageError;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _skuController.dispose();
    _titleController.dispose();
    _isbnController.dispose();
    _barcodeController.dispose();
    _editionController.dispose();
    _costController.dispose();
    _priceController.dispose();
    _reorderController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final lookups = context.read<LookupsRepository>();
    final products = context.read<ProductsRepository>();
    try {
      final results = await Future.wait([
        lookups.fetchLevels(),
        lookups.fetchSubjects(),
        lookups.fetchLanguages(),
        lookups.fetchPublishers(),
        if (widget.productId != null)
          products.getProduct(widget.productId!)
        else
          Future<Product?>.value(null),
      ]);
      if (!mounted) return;
      _levels = results[0] as List<LevelLookup>;
      _subjects = results[1] as List<NamedLookup>;
      _languages = results[2] as List<LanguageLookup>;
      _publishers = results[3] as List<NamedLookup>;
      final product = results[4] as Product?;
      if (product != null) {
        _skuController.text = product.sku;
        _titleController.text = product.title;
        _isbnController.text = product.isbn ?? '';
        _barcodeController.text = product.barcode ?? '';
        _editionController.text = product.edition ?? '';
        _costController.text = (product.costPrice / 100).toStringAsFixed(2);
        _priceController.text = (product.sellingPrice / 100).toStringAsFixed(2);
        _reorderController.text = product.reorderLevel.toString();
        _levelId = product.levelId;
        _subjectId = product.subjectId;
        _languageId = product.languageId;
        _publisherId = product.publisherId;
        _isActive = product.isActive;
      }
      setState(() => _loading = false);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  Future<void> _save() async {
    setState(() {
      _levelError = _levelId == null ? 'Required' : null;
      _subjectError = _subjectId == null ? 'Required' : null;
      _languageError = _languageId == null ? 'Required' : null;
    });
    if (!_formKey.currentState!.validate() ||
        _levelId == null ||
        _subjectId == null ||
        _languageId == null) {
      return;
    }
    final cost = Money.parseGhsToPesewas(_costController.text);
    final price = Money.parseGhsToPesewas(_priceController.text);
    if (cost == null || price == null) {
      setState(() => _error = 'Enter valid GHS amounts for cost and price.');
      return;
    }
    final reorder = int.tryParse(_reorderController.text.trim()) ?? 0;

    final payload = {
      'sku': _skuController.text.trim(),
      'title': _titleController.text.trim(),
      'isbn': _emptyToNull(_isbnController.text),
      'barcode': _emptyToNull(_barcodeController.text),
      'edition': _emptyToNull(_editionController.text),
      'level_id': _levelId,
      'subject_id': _subjectId,
      'language_id': _languageId,
      'publisher_id': _publisherId,
      'cost_price': cost,
      'selling_price': price,
      'reorder_level': reorder,
      'is_active': _isActive,
    };

    setState(() {
      _saving = true;
      _error = null;
    });

    final repo = context.read<ProductsRepository>();
    try {
      final Product saved;
      if (widget.isEditing) {
        saved = await repo.updateProduct(widget.productId!, payload);
      } else {
        saved = await repo.createProduct(payload);
      }
      if (!mounted) return;
      AppToast.show(
        context,
        widget.isEditing ? 'Product updated.' : 'Product created.',
        type: ToastType.success,
      );
      context.go('/products/${saved.id}');
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _saving = false;
      });
    }
  }

  String? _emptyToNull(String value) {
    final trimmed = value.trim();
    return trimmed.isEmpty ? null : trimmed;
  }

  InputDecoration _field(String label, {Widget? suffixIcon}) {
    const r = 12.0;
    return InputDecoration(
      labelText: label,
      suffixIcon: suffixIcon,
      filled: true,
      fillColor: AppColors.surface,
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(r),
        borderSide: const BorderSide(color: AppColors.line),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(r),
        borderSide: const BorderSide(color: AppColors.ink, width: 1.5),
      ),
      errorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(r),
        borderSide: const BorderSide(color: AppColors.danger),
      ),
      focusedErrorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(r),
        borderSide: const BorderSide(color: AppColors.danger, width: 1.5),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    const r = 12.0;
    return LoadingOverlay(
      isLoading: _saving,
      message: widget.isEditing ? 'Saving…' : 'Creating…',
      child: AppPage(
        title: widget.isEditing ? 'Edit product' : 'Add product',
        subtitle: widget.isEditing ? 'Update catalog details' : 'Manual entry',
        body: _loading
            ? const LoadingBody()
            : _error != null && _levels.isEmpty
                ? ErrorState(message: _error!, onRetry: _load)
                : Form(
                    key: _formKey,
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
                      children: [
                        Container(
                          width: double.infinity,
                          padding: const EdgeInsets.all(14),
                          decoration: BoxDecoration(
                            color: AppColors.limeSoft,
                            border: Border.all(color: AppColors.lime, width: 1.5),
                            borderRadius: BorderRadius.circular(r),
                          ),
                          child: Row(
                            children: [
                              Container(
                                width: 40,
                                height: 40,
                                decoration: BoxDecoration(
                                  color: AppColors.lime,
                                  borderRadius: BorderRadius.circular(10),
                                ),
                                child: const Center(
                                  child: AppIcon(AppIcons.products, size: 18),
                                ),
                              ),
                              const SizedBox(width: 12),
                              Expanded(
                                child: Text(
                                  widget.isEditing
                                      ? 'Change prices, codes, or classification. Stock still moves through receive / adjust.'
                                      : 'Manual catalog entry. Prefer the approved list when the title is on NaCCA.',
                                  style: const TextStyle(
                                    fontSize: 13,
                                    fontWeight: FontWeight.w700,
                                    height: 1.35,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 18),
                        const _FormSection('Identity'),
                        const SizedBox(height: 10),
                        TextFormField(
                          controller: _skuController,
                          decoration: _field('SKU'),
                          validator: (v) =>
                              v == null || v.trim().isEmpty ? 'Required' : null,
                        ),
                        const SizedBox(height: 12),
                        TextFormField(
                          controller: _titleController,
                          decoration: _field('Title'),
                          validator: (v) =>
                              v == null || v.trim().isEmpty ? 'Required' : null,
                        ),
                        const SizedBox(height: 18),
                        const _FormSection('Classification'),
                        const SizedBox(height: 10),
                        Container(
                          padding: const EdgeInsets.all(14),
                          decoration: BoxDecoration(
                            border: Border.all(color: AppColors.line),
                            borderRadius: BorderRadius.circular(r),
                          ),
                          child: Column(
                            children: [
                              OptionPicker<int>(
                                label: 'Level',
                                value: _levelId,
                                allowClear: false,
                                clearLabel: 'Choose level',
                                options:
                                    _levels.map((l) => (l.id, l.name)).toList(),
                                onChanged: (v) => setState(() {
                                  _levelId = v;
                                  _levelError = null;
                                }),
                              ),
                              if (_levelError != null) ...[
                                const SizedBox(height: 6),
                                Align(
                                  alignment: Alignment.centerLeft,
                                  child: Text(
                                    _levelError!,
                                    style: const TextStyle(
                                      color: AppColors.danger,
                                      fontWeight: FontWeight.w700,
                                      fontSize: 12.5,
                                    ),
                                  ),
                                ),
                              ],
                              const SizedBox(height: 14),
                              OptionPicker<int>(
                                label: 'Subject',
                                value: _subjectId,
                                allowClear: false,
                                clearLabel: 'Choose subject',
                                options: _subjects
                                    .map((s) => (s.id, s.name))
                                    .toList(),
                                onChanged: (v) => setState(() {
                                  _subjectId = v;
                                  _subjectError = null;
                                }),
                              ),
                              if (_subjectError != null) ...[
                                const SizedBox(height: 6),
                                Align(
                                  alignment: Alignment.centerLeft,
                                  child: Text(
                                    _subjectError!,
                                    style: const TextStyle(
                                      color: AppColors.danger,
                                      fontWeight: FontWeight.w700,
                                      fontSize: 12.5,
                                    ),
                                  ),
                                ),
                              ],
                              const SizedBox(height: 14),
                              OptionPicker<int>(
                                label: 'Language',
                                value: _languageId,
                                allowClear: false,
                                clearLabel: 'Choose language',
                                options: _languages
                                    .map((l) => (l.id, l.name))
                                    .toList(),
                                onChanged: (v) => setState(() {
                                  _languageId = v;
                                  _languageError = null;
                                }),
                              ),
                              if (_languageError != null) ...[
                                const SizedBox(height: 6),
                                Align(
                                  alignment: Alignment.centerLeft,
                                  child: Text(
                                    _languageError!,
                                    style: const TextStyle(
                                      color: AppColors.danger,
                                      fontWeight: FontWeight.w700,
                                      fontSize: 12.5,
                                    ),
                                  ),
                                ),
                              ],
                              const SizedBox(height: 14),
                              OptionPicker<int>(
                                label: 'Publisher',
                                value: _publisherId,
                                clearLabel: 'None',
                                options: _publishers
                                    .map((p) => (p.id, p.name))
                                    .toList(),
                                onChanged: (v) =>
                                    setState(() => _publisherId = v),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 18),
                        const _FormSection('Codes'),
                        const SizedBox(height: 10),
                        TextFormField(
                          controller: _isbnController,
                          decoration: _field('ISBN'),
                        ),
                        const SizedBox(height: 12),
                        TextFormField(
                          controller: _barcodeController,
                          decoration: _field(
                            'Barcode',
                            suffixIcon: IconButton(
                              tooltip: 'Scan',
                              onPressed: () async {
                                final code = await context.push<String>(
                                  '/products/scan?capture=1',
                                );
                                if (code != null) {
                                  _barcodeController.text = code;
                                }
                              },
                              icon: const AppIcon(AppIcons.scan, size: 20),
                            ),
                          ),
                        ),
                        const SizedBox(height: 12),
                        TextFormField(
                          controller: _editionController,
                          decoration: _field('Edition'),
                        ),
                        const SizedBox(height: 18),
                        const _FormSection('Pricing & stock'),
                        const SizedBox(height: 10),
                        Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Expanded(
                              child: TextFormField(
                                controller: _costController,
                                keyboardType:
                                    const TextInputType.numberWithOptions(
                                  decimal: true,
                                ),
                                decoration: _field('Cost (GHS)'),
                                validator: (v) =>
                                    Money.parseGhsToPesewas(v ?? '') == null
                                        ? 'Invalid'
                                        : null,
                              ),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: TextFormField(
                                controller: _priceController,
                                keyboardType:
                                    const TextInputType.numberWithOptions(
                                  decimal: true,
                                ),
                                decoration: _field('Selling (GHS)'),
                                validator: (v) =>
                                    Money.parseGhsToPesewas(v ?? '') == null
                                        ? 'Invalid'
                                        : null,
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 12),
                        TextFormField(
                          controller: _reorderController,
                          keyboardType: TextInputType.number,
                          decoration: _field('Reorder level'),
                        ),
                        const SizedBox(height: 12),
                        Container(
                          decoration: BoxDecoration(
                            color: AppColors.surface,
                            border: Border.all(color: AppColors.line),
                            borderRadius: BorderRadius.circular(r),
                          ),
                          child: SwitchListTile(
                            contentPadding: const EdgeInsets.symmetric(
                              horizontal: 14,
                            ),
                            title: const Text(
                              'Active',
                              style: TextStyle(fontWeight: FontWeight.w800),
                            ),
                            subtitle: const Text(
                              'Inactive products stay out of new sales',
                              style: TextStyle(
                                fontSize: 12.5,
                                fontWeight: FontWeight.w600,
                                color: AppColors.mute,
                              ),
                            ),
                            value: _isActive,
                            activeThumbColor: AppColors.ink,
                            activeTrackColor: AppColors.lime,
                            onChanged: (v) => setState(() => _isActive = v),
                          ),
                        ),
                        if (_error != null) ...[
                          const SizedBox(height: 14),
                          Text(
                            _error!,
                            style: const TextStyle(
                              color: AppColors.danger,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ],
                        const SizedBox(height: 20),
                        FilledButton(
                          onPressed: _saving ? null : _save,
                          style: FilledButton.styleFrom(
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(r),
                            ),
                          ),
                          child: Text(
                            widget.isEditing
                                ? 'Save changes'
                                : 'Create product',
                          ),
                        ),
                      ],
                    ),
                  ),
      ),
    );
  }
}

class _FormSection extends StatelessWidget {
  const _FormSection(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(
      text.toUpperCase(),
      style: const TextStyle(
        fontSize: 11,
        fontWeight: FontWeight.w800,
        letterSpacing: 0.8,
        color: AppColors.mute,
      ),
    );
  }
}
