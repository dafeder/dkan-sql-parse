./parse "SELECT record_number r, (record_number + (2+2)) e FROM tablename t WHERE ((somethingelse LIKE '%whatever') AND (something = 1))

./parse "SELECT record_number r, (record_number + (2+2)) e FROM tablename t WHERE something = 1