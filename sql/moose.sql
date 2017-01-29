/**
 * Created by Serge Titov for mooServer project
 * 2014 - 2016
 */

create table stamp
(
	stamp timestamp DEFAULT CURRENT_TIMESTAMP
);

create table phone
(
	id int primary key auto_increment,
	phone text(25) not null,
  canonical text(25) not null,
	active bit default 1,
  group_id int,       -- принадлежит организации
  demo bit default 1, -- принадлежит виден всем
	unique (phone(25)),
  unique (canonical(25)),
  foreign key (group_id) references users (id)
);


create table moose
(
	id int primary key auto_increment,
	phone_id int unique,
	name text(25) not null,
	comment text,
  group_id int,         -- принадлежит организации
  demo bit default 1,   -- принадлежит виден всем
	unique (name(25)),
	foreign key (phone_id) references phone (id),
  foreign key (group_id) references users (id)
);


create table raw_sms
(
	id int primary key auto_increment,
	phone_id int not null,
	stamp datetime not null,	-- время получения смс-гейтом
	text text(165),

	user_id int not null, 		-- кто ее добавил
	ip text(64) not null,
	xfw_ip text(64) not null,

	foreign key (user_id) references users (id),
	foreign key (phone_id) references phone (id), 
	unique (phone_id, text(165))   -- пока не надо :)
);

create table sms
(
	id int primary key auto_increment,
	moose int, 
	raw_sms_id int,

	int_id int not null,  -- internal id
	volt decimal(5,3) not null,
	temp decimal(3,1) not null,
	gps_on int not null,
	gsm_tries int not null,
	
	foreign key (raw_sms_id) references raw_sms (id),	
	foreign key (moose) references moose (id)
);

create table activity
(
	stamp datetime not null,
	active bit default 0,		
	sms_id int not null,
	primary key(stamp, sms_id),
  valid bit default 1,
	foreign key (sms_id) references sms (id)
);

create table position
(
	stamp datetime not null,
	sms_id int not null, 
	lat decimal(9,6),
	lon decimal(9,6),
  valid bit default 1,
	foreign key (sms_id) references sms (id)
);

insert into users (id, login, name, pwd, is_group, validated)
values
  (5, N'Feeders', null, null, 1, 0);

insert into users (login, name, pwd, is_group, validated)
	values
  (N'Demo', N'Demo organization', null, 1, 0), -- id = 1001
	(N'feeder', null, N'$2y$10$6PCyWHIxRYgnCGHO5GQfW.1a08wkg06Ek.QNgkk0E5foS6SbUMfFm', 0, 1),
	(N'admin', null,  N'$2y$10$JopWuetb/IXfmv0sotC20O5M.ihvFWL1d9nWi7shtNf.SAHwa0ehO', 0, 1);


insert into usergroups (user_id, group_id)
	values
  (1, 1001),
	(3, 5),
	(1002, 5),
  (1002, 1001),
	(1003, 2),
  (1003, 1001);

alter table users add column is_gate bit default 0;
alter table moose add column upd_stamp datetime DEFAULT "1980-01-01 00:00:00";
-- create index activity_sms on activity (sms_id);

alter table sms add column maxt datetime default 0;
alter table sms add column mint datetime default 0;

update sms s set maxt = (select max(stamp) from position p where p.sms_id = s.id);
update sms s set mint = (select min(stamp) from position p where p.sms_id = s.id);

alter table sms add column diagnose text(200);

/*
select m.id, m.name, count(s.id), min(s.mint), max(s.maxt) from sms s inner join moose m on m.id = s.moose  group by m.id, m.name;
*/

