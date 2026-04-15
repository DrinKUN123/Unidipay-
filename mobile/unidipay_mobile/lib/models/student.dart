class Student {
  final int id;
  final String name;
  final String studentId;
  final String? program;
  final String? yearLevel;
  final String? nfcCardId;
  final double balance;

  Student({
    required this.id,
    required this.name,
    required this.studentId,
    required this.program,
    required this.yearLevel,
    required this.nfcCardId,
    required this.balance,
  });

  Student copyWith({double? balance}) {
    return Student(
      id: id,
      name: name,
      studentId: studentId,
      program: program,
      yearLevel: yearLevel,
      nfcCardId: nfcCardId,
      balance: balance ?? this.balance,
    );
  }

  factory Student.fromJson(Map<String, dynamic> json) {
    final rawId = json['id'];
    final parsedId = rawId is num ? rawId.toInt() : int.tryParse(rawId?.toString() ?? '') ?? 0;

    return Student(
      id: parsedId,
      name: (json['name'] ?? '').toString(),
      studentId: (json['student_id'] ?? '').toString(),
      program: json['program']?.toString(),
      yearLevel: json['year_level']?.toString(),
      nfcCardId: json['nfc_card_id']?.toString(),
      balance: (json['balance'] as num?)?.toDouble() ?? 0,
    );
  }
}
