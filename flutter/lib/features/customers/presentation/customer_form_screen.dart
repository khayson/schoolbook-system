import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/validators.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

/// Create (no [customerId]) or edit a customer. Balances are never edited here.
class CustomerFormScreen extends StatefulWidget {
  const CustomerFormScreen({super.key, this.customerId});

  final int? customerId;

  @override
  State<CustomerFormScreen> createState() => _CustomerFormScreenState();
}

class _CustomerFormScreenState extends State<CustomerFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _district = TextEditingController();
  final _address = TextEditingController();
  final _contact = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  final _creditLimit = TextEditingController();
  final _notes = TextEditingController();
  String _type = 'school';
  String? _region;
  bool _active = true;

  bool _loading = false;
  bool _saving = false;
  String? _loadError;
  ApiException? _saveError;

  bool get _isEdit => widget.customerId != null;

  @override
  void initState() {
    super.initState();
    if (_isEdit) {
      _load();
    }
  }

  @override
  void dispose() {
    for (final c in [_name, _district, _address, _contact, _phone, _email, _creditLimit, _notes]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _loadError = null;
    });
    try {
      final c = await context.read<CustomersRepository>().getCustomer(widget.customerId!);
      _name.text = c.name;
      _district.text = c.district ?? '';
      _address.text = c.address ?? '';
      _contact.text = c.contactPerson ?? '';
      _phone.text = c.phone ?? '';
      _email.text = c.email ?? '';
      _creditLimit.text = c.creditLimit == null ? '' : Money.toGhsInput(c.creditLimit!);
      _notes.text = c.notes ?? '';
      _type = c.type;
      _region = CustomerOptions.regions.contains(c.region) ? c.region : null;
      _active = c.isActive;
    } on ApiException catch (e) {
      _loadError = e.message;
    } finally {
      if (mounted) {
        setState(() => _loading = false);
      }
    }
  }

  String? _blankToNull(TextEditingController c) => c.text.trim().isEmpty ? null : c.text.trim();

  Future<void> _save() async {
    if (!(_formKey.currentState?.validate() ?? false)) {
      return;
    }
    final payload = <String, dynamic>{
      'name': _name.text.trim(),
      'type': _type,
      'region': _region,
      'district': _blankToNull(_district),
      'address': _blankToNull(_address),
      'contact_person': _blankToNull(_contact),
      'phone': _blankToNull(_phone),
      'email': _blankToNull(_email),
      'credit_limit': _creditLimit.text.trim().isEmpty ? null : Money.parseGhsToPesewas(_creditLimit.text),
      'notes': _blankToNull(_notes),
      'is_active': _active,
    };

    setState(() {
      _saving = true;
      _saveError = null;
    });
    final repository = context.read<CustomersRepository>();
    try {
      final saved = _isEdit
          ? await repository.updateCustomer(widget.customerId!, payload)
          : await repository.createCustomer(payload);
      if (!mounted) {
        return;
      }
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('${saved.name} saved')));
      _isEdit ? context.pop() : context.pushReplacement('/customers/${saved.id}');
    } on ApiException catch (e) {
      setState(() => _saveError = e);
    } finally {
      if (mounted) {
        setState(() => _saving = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(_isEdit ? 'Edit customer' : 'New customer')),
      body: _loading
          ? const LoadingBody()
          : _loadError != null
              ? ErrorState(message: _loadError!, onRetry: _load)
              : Form(
                  key: _formKey,
                  child: ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      _field(_name, 'Name', key: 'cust_name', validator: (v) => Validators.required(v, label: 'Name')),
                      DropdownButtonFormField<String>(
                        key: ValueKey('cust_type_$_type'),
                        initialValue: _type,
                        decoration: const InputDecoration(labelText: 'Type', border: OutlineInputBorder()),
                        items: [
                          for (final e in CustomerOptions.types.entries) DropdownMenuItem(value: e.key, child: Text(e.value)),
                        ],
                        onChanged: (v) => setState(() => _type = v ?? 'school'),
                      ),
                      const SizedBox(height: 12),
                      DropdownButtonFormField<String>(
                        key: ValueKey('cust_region_$_region'),
                        initialValue: _region,
                        decoration: const InputDecoration(labelText: 'Region', border: OutlineInputBorder()),
                        items: [
                          for (final r in CustomerOptions.regions) DropdownMenuItem(value: r, child: Text(r)),
                        ],
                        validator: (v) => v == null ? 'Pick a region' : null,
                        onChanged: (v) => setState(() => _region = v),
                      ),
                      const SizedBox(height: 12),
                      _field(_district, 'District'),
                      _field(_address, 'Address', maxLines: 2),
                      _field(_contact, 'Contact person'),
                      _field(_phone, 'Phone', keyboard: TextInputType.phone),
                      _field(_email, 'Email', keyboard: TextInputType.emailAddress, validator: Validators.optionalEmail),
                      _field(
                        _creditLimit,
                        'Credit limit (GHS, blank = no limit)',
                        key: 'cust_credit_limit',
                        keyboard: const TextInputType.numberWithOptions(decimal: true),
                        validator: Validators.optionalGhsAmount,
                      ),
                      _field(_notes, 'Notes', maxLines: 3),
                      if (_isEdit)
                        SwitchListTile(
                          contentPadding: EdgeInsets.zero,
                          title: const Text('Active'),
                          subtitle: const Text('Inactive customers can still pay but cannot get new sales.'),
                          value: _active,
                          onChanged: (v) => setState(() => _active = v),
                        ),
                      if (_saveError != null) ...[
                        const SizedBox(height: 8),
                        Text(
                          describeApiError(_saveError!).body,
                          style: TextStyle(color: Theme.of(context).colorScheme.error),
                        ),
                      ],
                      const SizedBox(height: 16),
                      FilledButton(
                        key: const Key('cust_save'),
                        onPressed: _saving ? null : _save,
                        style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                        child: Text(_saving ? 'Saving…' : 'Save'),
                      ),
                    ],
                  ),
                ),
    );
  }

  Widget _field(
    TextEditingController controller,
    String label, {
    String? key,
    int maxLines = 1,
    TextInputType? keyboard,
    String? Function(String?)? validator,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: TextFormField(
        key: key == null ? null : Key(key),
        controller: controller,
        maxLines: maxLines,
        keyboardType: keyboard,
        validator: validator,
        decoration: InputDecoration(labelText: label, border: const OutlineInputBorder()),
      ),
    );
  }
}
