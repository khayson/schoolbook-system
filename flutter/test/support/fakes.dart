import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/auth_token_store.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/pagination.dart';
import 'package:schoolbook/core/pdf_sharer.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/payments/data/payments_repository.dart';
import 'package:schoolbook/features/payments/domain/payment.dart';

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
