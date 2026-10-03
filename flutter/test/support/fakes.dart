import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/auth_token_store.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/pagination.dart';
import 'package:schoolbook/core/pdf_sharer.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/payments/data/payments_repository.dart';
import 'package:schoolbook/features/payments/domain/payment.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/sales/data/sales_repository.dart';
import 'package:schoolbook/features/sales/domain/sale.dart';

ApiClient unusedApiClient() => ApiClient(tokenStore: AuthTokenStore());

Customer testCustomer({int id = 1, int owes = 300000, int credit = 0}) => Customer(
      id: id,
      code: 'CUS-0001',
      name: 'Akwaaba Basic School',
      type: 'school',
      region: 'Ashanti',
      creditBalance: credit,
      outstandingBalance: owes,
      isActive: true,
    );

Payment testPayment({
  int id = 7,
  String receiptNo = 'RCT-2026-000007',
  int amount = 50000,
  String method = 'momo',
  String? reference = 'MP-1',
  int unallocated = 0,
  String status = 'valid',
}) =>
    Payment(
      id: id,
      receiptNo: receiptNo,
      customerId: 1,
      amount: amount,
      method: method,
      reference: reference,
      paidAt: DateTime(2026, 10, 1, 9, 30),
      unallocatedAmount: unallocated,
      status: status,
    );

PaginatedResponse<T> page<T>(List<T> items) => PaginatedResponse(
      data: items,
      meta: PaginatedMeta(currentPage: 1, lastPage: 1, perPage: 25, total: items.length),
    );

/// Records every call; [recordResults] are consumed in order (an [ApiException]
/// is thrown, a [Payment] is returned).
class FakePaymentsRepository extends PaymentsRepository {
  FakePaymentsRepository({List<Object>? recordResults, this.recent = const []})
      : recordResults = recordResults ?? [],
        super(apiClient: unusedApiClient());

  final List<Object> recordResults;
  List<Payment> recent;
  final List<({String key, Map<String, dynamic> payload})> recordCalls = [];
  int listCalls = 0;

  @override
  Future<PaginatedResponse<Payment>> listPayments({
    int page = 1,
    int perPage = 25,
    int? customerId,
    String? status,
  }) async {
    listCalls++;
    return PaginatedResponse(
      data: recent,
      meta: PaginatedMeta(currentPage: 1, lastPage: 1, perPage: perPage, total: recent.length),
    );
  }

  @override
  Future<Payment> recordPayment({required String idempotencyKey, required Map<String, dynamic> payload}) async {
    recordCalls.add((key: idempotencyKey, payload: payload));
    final next = recordResults.isEmpty ? testPayment() : recordResults.removeAt(0);
    if (next is ApiException) {
      throw next;
    }
    return next as Payment;
  }

  @override
  Future<Payment> getPayment(int id) async => testPayment(id: id);

  @override
  Future<List<int>> receiptPdf(int paymentId) async => [37, 80, 68, 70];
}

class FakeCustomersRepository extends CustomersRepository {
  FakeCustomersRepository({Customer? customer})
      : customer = customer ?? testCustomer(),
        super(apiClient: unusedApiClient());

  Customer customer;

  @override
  Future<Customer> getCustomer(int id) async => customer;
}

class FakePdfSharer implements PdfSharer {
  final List<String> shared = [];

  @override
  Future<void> sharePdf(List<int> bytes, {required String fileName, String? subject}) async {
    shared.add(fileName);
  }
}

Sale testSale({
  int id = 5,
  String status = 'draft',
  int total = 3000,
  String updatedAt = '2026-10-02T09:00:00.000000Z',
  String? invoiceNo,
  int creditBalance = 0,
  List<SaleLine>? items,
}) =>
    Sale(
      id: id,
      invoiceNo: invoiceNo,
      customerId: 1,
      customer: testCustomer(credit: creditBalance),
      status: status,
      paymentStatus: 'unpaid',
      saleDate: DateTime(2026, 10, 2),
      subtotal: total,
      discountTotal: 0,
      total: total,
      amountPaid: 0,
      balanceDue: status == 'confirmed' ? total : 0,
      updatedAt: updatedAt,
      items: items ??
          const [
            SaleLine(productId: 11, productTitle: 'English Reader P4', quantity: 3, basePrice: 1000, unitPrice: 1000, lineTotal: 3000),
          ],
    );

/// Scriptable sales API: [confirmResults] / [createResults] are consumed in order.
class FakeSalesRepository extends SalesRepository {
  FakeSalesRepository({
    List<Object>? confirmResults,
    List<Object>? createResults,
    Sale? sale,
    this.previewDelay = Duration.zero,
  })  : confirmResults = confirmResults ?? [],
        createResults = createResults ?? [],
        sale = sale ?? testSale(),
        super(apiClient: unusedApiClient());

  final List<Object> confirmResults;
  final List<Object> createResults;
  Sale sale;
  Duration previewDelay;

  final List<({String key, Map<String, dynamic> options})> confirmCalls = [];
  final List<({String key, Map<String, dynamic> payload})> createCalls = [];
  final List<Map<String, dynamic>> updateCalls = [];
  final List<List<Map<String, dynamic>>> previewCalls = [];

  /// What `PUT {}` returns (the re-priced draft).
  Sale? repriced;

  /// Per-call preview results; default prices every line at 1000.
  PricedOrder Function(List<Map<String, dynamic>> items)? previewResult;

  @override
  Future<Sale> getSale(int id) async => sale;

  @override
  Future<Sale> confirm(int id, {required String idempotencyKey, required Map<String, dynamic> options}) async {
    confirmCalls.add((key: idempotencyKey, options: options));
    final next = confirmResults.isEmpty ? testSale(status: 'confirmed', invoiceNo: 'INV-2026-000001') : confirmResults.removeAt(0);
    if (next is ApiException) {
      throw next;
    }
    sale = next as Sale;
    return sale;
  }

  @override
  Future<Sale> updateDraft(int id, Map<String, dynamic> payload) async {
    updateCalls.add(payload);
    sale = repriced ?? sale;
    return sale;
  }

  @override
  Future<Sale> createDraft({required String idempotencyKey, required Map<String, dynamic> payload}) async {
    createCalls.add((key: idempotencyKey, payload: payload));
    final next = createResults.isEmpty ? testSale() : createResults.removeAt(0);
    if (next is ApiException) {
      throw next;
    }
    return next as Sale;
  }

  @override
  Future<PricedOrder> preview({int? customerId, required List<Map<String, dynamic>> items}) async {
    previewCalls.add(items);
    if (previewDelay > Duration.zero) {
      await Future<void>.delayed(previewDelay);
    }
    if (previewResult != null) {
      return previewResult!(items);
    }
    final lines = [
      for (final i in items)
        SaleLine(
          productId: i['product_id'] as int,
          productTitle: 'Book ${i['product_id']}',
          quantity: i['quantity'] as int,
          basePrice: 1000,
          unitPrice: 1000,
          lineTotal: 1000 * (i['quantity'] as int),
        ),
    ];
    final total = lines.fold<int>(0, (sum, l) => sum + l.lineTotal);
    return PricedOrder(lines: lines, subtotal: total, total: total);
  }

  /// When set, the action throws it and the stored sale becomes [afterError]
  /// (as if an earlier attempt had already changed it).
  ApiException? actionError;
  Sale? afterError;

  Future<Sale> _maybeFail(Sale Function() ok) async {
    if (actionError != null) {
      sale = afterError ?? sale;
      throw actionError!;
    }
    return sale = ok();
  }

  @override
  Future<Sale> cancel(int id, {String? reason}) => _maybeFail(() => testSale(status: 'cancelled'));

  @override
  Future<Sale> voidSale(int id, String reason) => _maybeFail(() => testSale(status: 'void', invoiceNo: sale.invoiceNo));

  @override
  Future<Sale> deliver(int id) => _maybeFail(() => sale);

  @override
  Future<List<int>> invoicePdf(int id) async => [37, 80, 68, 70];
}

Product testProduct({int id = 11, String title = 'English Reader P4', int stock = 50}) => Product.fromJson({
      'id': id,
      'sku': 'SKU-$id',
      'title': title,
      'level_id': 1,
      'subject_id': 1,
      'language_id': 1,
      'cost_price': 600,
      'selling_price': 1000,
      'reorder_level': 0,
      'stock_on_hand': stock,
      'is_active': true,
    });
