import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/features/catalog/data/lookups_repository.dart';
import 'package:schoolbook/features/catalog/domain/lookup_models.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

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
      if (!mounted) {
        return;
      }
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
      setState(() {
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) {
        return;
      }
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) {
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
      if (!mounted) {
        return;
      }
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.isEditing ? 'Edit product' : 'Add product'),
      ),
      body: _loading
          ? const LoadingBody()
          : _error != null && _levels.isEmpty
              ? ErrorState(message: _error!, onRetry: _load)
              : Form(
                  key: _formKey,
                  child: ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      TextFormField(
                        controller: _skuController,
                        decoration: const InputDecoration(
                          labelText: 'SKU',
                          border: OutlineInputBorder(),
                        ),
                        validator: (v) =>
                            v == null || v.trim().isEmpty ? 'Required' : null,
                      ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: _titleController,
                        decoration: const InputDecoration(
                          labelText: 'Title',
                          border: OutlineInputBorder(),
                        ),
                        validator: (v) =>
                            v == null || v.trim().isEmpty ? 'Required' : null,
                      ),
                      const SizedBox(height: 12),
                      DropdownButtonFormField<int>(
                        initialValue: _levelId,
                        decoration: const InputDecoration(
                          labelText: 'Level',
                          border: OutlineInputBorder(),
                        ),
                        items: _levels
                            .map(
                              (l) => DropdownMenuItem(
                                value: l.id,
                                child: Text(l.name),
                              ),
                            )
                            .toList(),
                        onChanged: (v) => setState(() => _levelId = v),
                        validator: (v) => v == null ? 'Required' : null,
                      ),
                      const SizedBox(height: 12),
                      DropdownButtonFormField<int>(
                        initialValue: _subjectId,
                        decoration: const InputDecoration(
                          labelText: 'Subject',
                          border: OutlineInputBorder(),
                        ),
                        items: _subjects
                            .map(
                              (s) => DropdownMenuItem(
                                value: s.id,
                                child: Text(s.name),
                              ),
                            )
                            .toList(),
                        onChanged: (v) => setState(() => _subjectId = v),
                        validator: (v) => v == null ? 'Required' : null,
                      ),
                      const SizedBox(height: 12),
                      DropdownButtonFormField<int>(
                        initialValue: _languageId,
                        decoration: const InputDecoration(
                          labelText: 'Language',
                          border: OutlineInputBorder(),
                        ),
                        items: _languages
                            .map(
                              (l) => DropdownMenuItem(
                                value: l.id,
                                child: Text(l.name),
                              ),
                            )
                            .toList(),
                        onChanged: (v) => setState(() => _languageId = v),
                        validator: (v) => v == null ? 'Required' : null,
                      ),
                      const SizedBox(height: 12),
                      DropdownButtonFormField<int?>(
                        initialValue: _publisherId,
                        decoration: const InputDecoration(
                          labelText: 'Publisher (optional)',
                          border: OutlineInputBorder(),
                        ),
                        items: [
                          const DropdownMenuItem<int?>(
                            value: null,
                            child: Text('None'),
                          ),
                          ..._publishers.map(
                            (p) => DropdownMenuItem(
                              value: p.id,
                              child: Text(p.name),
                            ),
                          ),
                        ],
                        onChanged: (v) => setState(() => _publisherId = v),
                      ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: _isbnController,
                        decoration: const InputDecoration(
                          labelText: 'ISBN',
                          border: OutlineInputBorder(),
                        ),
                      ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: _barcodeController,
                        decoration: const InputDecoration(
                          labelText: 'Barcode',
                          border: OutlineInputBorder(),
                        ),
                      ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: _editionController,
                        decoration: const InputDecoration(
                          labelText: 'Edition',
                          border: OutlineInputBorder(),
                        ),
                      ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: _costController,
                        keyboardType: const TextInputType.numberWithOptions(
                          decimal: true,
                        ),
                        decoration: const InputDecoration(
                          labelText: 'Cost price (GHS)',
                          border: OutlineInputBorder(),
                        ),
                        validator: (v) =>
                            Money.parseGhsToPesewas(v ?? '') == null
                                ? 'Invalid amount'
                                : null,
                      ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: _priceController,
                        keyboardType: const TextInputType.numberWithOptions(
                          decimal: true,
                        ),
                        decoration: const InputDecoration(
                          labelText: 'Selling price (GHS)',
                          border: OutlineInputBorder(),
                        ),
                        validator: (v) =>
                            Money.parseGhsToPesewas(v ?? '') == null
                                ? 'Invalid amount'
                                : null,
                      ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: _reorderController,
                        keyboardType: TextInputType.number,
                        decoration: const InputDecoration(
                          labelText: 'Reorder level',
                          border: OutlineInputBorder(),
                        ),
                      ),
                      SwitchListTile(
                        contentPadding: EdgeInsets.zero,
                        title: const Text('Active'),
                        value: _isActive,
                        onChanged: (v) => setState(() => _isActive = v),
                      ),
                      if (_error != null) ...[
                        Text(
                          _error!,
                          style: TextStyle(
                            color: Theme.of(context).colorScheme.error,
                          ),
                        ),
                        const SizedBox(height: 8),
                      ],
                      FilledButton(
                        onPressed: _saving ? null : _save,
                        style: FilledButton.styleFrom(
                          minimumSize: const Size.fromHeight(52),
                        ),
                        child: _saving
                            ? const SizedBox(
                                height: 22,
                                width: 22,
                                child:
                                    CircularProgressIndicator(strokeWidth: 2),
                              )
                            : Text(widget.isEditing ? 'Save changes' : 'Create'),
                      ),
                    ],
                  ),
                ),
    );
  }
}
